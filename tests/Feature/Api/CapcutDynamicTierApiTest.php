<?php

namespace Tests\Feature\Api;

use App\Models\CapcutAccount;
use App\Models\CapcutPartnerOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class CapcutDynamicTierApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-10-27 03:00:00', 'UTC'));
        config(['services.internal_api_key' => 'test-internal-key']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    protected function headers(): array
    {
        return ['X-Internal-Key' => 'test-internal-key'];
    }

    private function account(string $email, float $ageDays, string $status = 'ready'): CapcutAccount
    {
        $account = CapcutAccount::create(['email' => $email, 'password' => 'secret-'.$email, 'status' => $status]);
        $account->forceFill(['created_at' => Carbon::now()->subSeconds((int) round($ageDays * 86400))])->save();

        return $account->fresh();
    }

    private function reserve(string $orderId, int $ttl = 15)
    {
        return $this->withHeaders($this->headers())->postJson('/api/internal/capcut/reservations', [
            'order_id' => $orderId,
            'package_id' => '1m',
            'buyer_reference' => '81234567',
            'ttl_minutes' => $ttl,
        ]);
    }

    public function test_stock_and_packages_expose_lowest_price_per_tier_without_secrets(): void
    {
        $this->account('full@capcut.com', 0);
        $this->account('mid@capcut.com', 10);
        $this->account('clearance@capcut.com', 23);

        $stock = $this->withHeaders($this->headers())->getJson('/api/internal/capcut/stock');
        $stock->assertStatus(200)
            ->assertJson([
                'status' => 'success',
                'ready_stock' => 3,
                'lowest_price' => 10000,
            ]);

        $tiers = collect($stock->json('tiers'))->keyBy('tier');
        $this->assertSame(1, $tiers['full']['ready_stock']);
        $this->assertSame(1, $tiers['mid']['ready_stock']);
        $this->assertSame(1, $tiers['clearance']['ready_stock']);
        $this->assertSame(0, $tiers['low']['ready_stock']);
        $this->assertSame(25000, $tiers['full']['price']);
        $this->assertSame(10000, $tiers['clearance']['price']);

        $packages = $this->withHeaders($this->headers())->getJson('/api/internal/capcut/packages');
        $packages->assertStatus(200)
            ->assertJson(['lowest_price' => 10000, 'stock_validity_days' => 30]);

        // Jangan pernah bocorkan email/password akun stok ke bot.
        $body = $stock->getContent().$packages->getContent();
        $this->assertStringNotContainsString('full@capcut.com', $body);
        $this->assertStringNotContainsString('secret-', $body);
    }

    public function test_reserve_locks_exact_tier_price_and_stock_expiry_snapshot(): void
    {
        $this->account('cheap@capcut.com', 23);

        $response = $this->reserve('CPCT-TIER-001');
        $response->assertStatus(201)->assertJson([
            'status' => 'success',
            'data' => [
                'status' => 'reserved',
                'package' => ['tier' => 'clearance', 'price' => 10000.0, 'currency' => 'IDR'],
                'stock_expires_at' => Carbon::now()->addDays(7)->toDateString(),
            ],
        ]);

        $this->assertArrayNotHasKey('account', $response->json('data'));

        $order = CapcutPartnerOrder::where('order_id', 'CPCT-TIER-001')->firstOrFail();
        $this->assertSame('clearance', $order->tier);
        $this->assertSame('10000', (string) (int) $order->price);
        $this->assertSame('reserved', CapcutAccount::where('email', 'cheap@capcut.com')->first()->status);
    }

    public function test_reserve_returns_409_when_only_expired_stock_remains(): void
    {
        $this->account('expired@capcut.com', 30.5);

        $this->reserve('CPCT-EMPTY-001')->assertStatus(409)
            ->assertJson(['status' => 'error', 'code' => 'OUT_OF_STOCK']);
    }

    public function test_bot_reservation_prevents_web_from_allocating_the_same_account(): void
    {
        $oldest = $this->account('oldest@capcut.com', 23);
        $this->account('fresh@capcut.com', 0);

        $response = $this->reserve('CPCT-RACE-001');
        $response->assertStatus(201);

        $this->assertSame('reserved', $oldest->fresh()->status);
        $this->assertSame('telegram', $oldest->fresh()->channel);

        // Web hanya boleh melihat satu akun yang masih sellable (bukan yang sudah direservasi bot).
        $this->assertSame(1, CapcutAccount::query()->sellable()->count());

        $second = $this->reserve('CPCT-RACE-002');
        $second->assertStatus(201)->assertJson(['data' => ['package' => ['price' => 25000.0]]]);

        $this->assertSame($oldest->id, CapcutPartnerOrder::where('order_id', 'CPCT-RACE-001')->first()->capcut_account_id);
        $this->assertNotSame(
            $oldest->id,
            CapcutPartnerOrder::where('order_id', 'CPCT-RACE-002')->first()->capcut_account_id
        );
    }

    public function test_claim_uses_reservation_snapshot_not_global_catalog(): void
    {
        $account = $this->account('snapshot@capcut.com', 23);
        $this->reserve('CPCT-SNAP-001')->assertStatus(201);

        $order = CapcutPartnerOrder::where('order_id', 'CPCT-SNAP-001')->firstOrFail();
        $snapshotEnd = $order->stock_expires_at->toDateString();

        // Katalog global berubah (akun "dimudakan"), snapshot reservasi tidak boleh ikut.
        $account->forceFill(['created_at' => Carbon::now()])->save();

        $this->withHeaders($this->headers())->postJson('/api/internal/capcut/claim', [
            'order_id' => 'CPCT-SNAP-001',
            'payment_reference' => 'PAY-SNAP-001',
            'amount' => 25000,
            'currency' => 'IDR',
            'verified_at' => Carbon::now()->toIso8601String(),
        ])->assertStatus(422)->assertJson(['code' => 'AMOUNT_CURRENCY_MISMATCH']);

        $claim = $this->withHeaders($this->headers())->postJson('/api/internal/capcut/claim', [
            'order_id' => 'CPCT-SNAP-001',
            'payment_reference' => 'PAY-SNAP-001',
            'amount' => 10000,
            'currency' => 'IDR',
            'verified_at' => Carbon::now()->toIso8601String(),
        ]);

        $claim->assertStatus(200)->assertJson([
            'status' => 'success',
            'data' => [
                'status' => 'completed',
                'end_date' => $snapshotEnd,
                'package' => ['tier' => 'clearance', 'price' => 10000.0],
                'account' => ['email' => 'snapshot@capcut.com'],
            ],
        ]);

        $this->assertSame('sold', $account->fresh()->status);
    }

    public function test_cancel_reservation_returns_account_to_stock(): void
    {
        $account = $this->account('cancel@capcut.com', 10);
        $reserved = $this->reserve('CPCT-CANCEL-001');
        $reserved->assertStatus(201);

        $this->withHeaders($this->headers())->postJson('/api/internal/capcut/cancel-reservation', [
            'order_id' => 'CPCT-CANCEL-001',
            'reason' => 'pembeli batal',
        ])->assertStatus(200)->assertJson(['data' => ['status' => 'cancelled']]);

        $account->refresh();
        $this->assertSame('ready', $account->status);
        $this->assertNull($account->channel);
        $this->assertSame(20000, $account->tierInfo()['price']);
        $this->assertSame(1, CapcutAccount::query()->sellable()->count());
    }

    public function test_expired_reservation_is_released_and_stock_cannot_be_oversold(): void
    {
        $account = $this->account('ttl@capcut.com', 23);
        $this->reserve('CPCT-TTL-001', 5)->assertStatus(201);

        Carbon::setTestNow(Carbon::now()->addMinutes(6));

        $stock = $this->withHeaders($this->headers())->getJson('/api/internal/capcut/stock');
        $stock->assertStatus(200)->assertJson(['ready_stock' => 1, 'lowest_price' => 10000]);

        $this->assertSame('ready', $account->fresh()->status);
        $this->assertSame('expired', CapcutPartnerOrder::where('order_id', 'CPCT-TTL-001')->first()->status);
    }
}