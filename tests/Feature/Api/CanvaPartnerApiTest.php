<?php

namespace Tests\Feature\Api;

use App\Models\CanvaAccount;
use App\Models\CanvaPartnerOrder;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CanvaPartnerApiTest extends TestCase
{
    use RefreshDatabase;

    protected function apiKey(): string
    {
        return config('services.internal_api_key');
    }

    protected function headers(): array
    {
        return ['X-Internal-Key' => $this->apiKey()];
    }

    public function test_rejects_unauthorized_request(): void
    {
        $this->getJson('/api/internal/canva/packages')->assertStatus(401);
        $this->getJson('/api/internal/canva/stock')->assertStatus(401);
        $this->postJson('/api/internal/canva/reservations')->assertStatus(401);
        $this->postJson('/api/internal/canva/claim')->assertStatus(401);
    }

    public function test_packages_returns_supported_package_catalog(): void
    {
        $response = $this->withHeaders($this->headers())->getJson('/api/internal/canva/packages');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'status',
                'currency',
                'ttl_minutes',
                'packages' => [
                    '*' => ['id', 'name', 'duration_days', 'warranty_days', 'price', 'currency', 'expiry_rules'],
                ],
            ]);
    }

    public function test_stock_auto_expires_stale_reservations(): void
    {
        $ready = CanvaAccount::create(['email' => 'ready@gmail.com', 'password' => 'p', 'status' => 'ready']);
        $staleAcc = CanvaAccount::create(['email' => 'stale@gmail.com', 'password' => 'p', 'status' => 'reserved']);

        CanvaPartnerOrder::create([
            'order_id' => 'STALE-CNV-001',
            'reservation_id' => 'uuid-stale-cnv',
            'payload_hash' => 'hash',
            'package_id' => '1m',
            'buyer_reference' => '12345678',
            'status' => 'reserved',
            'canva_account_id' => $staleAcc->id,
            'price' => 15000,
            'currency' => 'IDR',
            'reserved_until' => now()->subMinutes(5),
            'otp_token' => 'token-stale',
        ]);

        $response = $this->withHeaders($this->headers())->getJson('/api/internal/canva/stock');

        $response->assertStatus(200)
            ->assertJson([
                'status' => 'success',
                'ready_stock' => 2,
            ]);

        $this->assertEquals('ready', $staleAcc->fresh()->status);
    }

    public function test_reserve_atomically_locks_account_and_clamps_ttl(): void
    {
        $acc = CanvaAccount::create([
            'email' => 'stock@canva.com',
            'password' => 'p123',
            'status' => 'ready',
        ]);

        $response = $this->withHeaders($this->headers())->postJson('/api/internal/canva/reservations', [
            'order_id' => 'CNV-ORDER-101',
            'package_id' => '1m',
            'buyer_reference' => '987654321',
            'buyer_username' => 'buyer_tele',
            'ttl_minutes' => 60, // clamped to 30
        ]);

        $response->assertStatus(201)
            ->assertJson([
                'status' => 'success',
                'data' => [
                    'order_id' => 'CNV-ORDER-101',
                    'status' => 'reserved',
                    'ttl_minutes' => 30,
                    'stock_id' => $acc->id,
                ],
            ]);

        $this->assertEquals('reserved', $acc->fresh()->status);
    }

    public function test_claim_with_valid_payment_delivers_credentials(): void
    {
        $acc = CanvaAccount::create([
            'email' => 'claim@canva.com',
            'password' => 'canvapro99',
            'status' => 'ready',
        ]);

        $this->withHeaders($this->headers())->postJson('/api/internal/canva/reservations', [
            'order_id' => 'CNV-CLAIM-001',
            'package_id' => '1m',
            'buyer_reference' => '12345678',
        ]);

        $response = $this->withHeaders($this->headers())->postJson('/api/internal/canva/claim', [
            'order_id' => 'CNV-CLAIM-001',
            'payment_reference' => 'QRIS-CNV-998877',
            'amount' => 15000,
            'currency' => 'IDR',
            'verified_at' => now()->toIso8601String(),
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'status' => 'success',
                'data' => [
                    'order_id' => 'CNV-CLAIM-001',
                    'status' => 'completed',
                    'account' => [
                        'email' => 'claim@canva.com',
                        'password' => 'canvapro99',
                    ],
                ],
            ]);

        $this->assertEquals('sold', $acc->fresh()->status);
        $this->assertEquals('telegram', $acc->fresh()->channel);
    }

    public function test_order_inquiry_recovers_state(): void
    {
        $acc = CanvaAccount::create(['email' => 'inq@canva.com', 'password' => 'p', 'status' => 'ready']);

        $this->withHeaders($this->headers())->postJson('/api/internal/canva/reservations', [
            'order_id' => 'CNV-INQ-001',
            'package_id' => '1m',
            'buyer_reference' => '12345678',
        ]);

        $res = $this->withHeaders($this->headers())->getJson('/api/internal/canva/orders/CNV-INQ-001');
        $res->assertStatus(200)
        ->assertJson([
            'status' => 'success',
            'data' => [
                'order_id' => 'CNV-INQ-001',
                'status' => 'reserved',
            ],
        ]);
        }

        public function test_claim_verified_at_round_trips_wib_utc_replay_and_get(): void
        {
        // 06:30 WIB = 23:30 UTC hari sebelumnya: sekaligus menguji rollover tanggal.
        $wib = Carbon::parse('2026-10-22 06:30:00', 'Asia/Jakarta');

        foreach (['wib' => $wib, 'utc' => $wib->copy()->setTimezone('UTC')] as $label => $input) {
        $orderId = 'CNV-TZ-'.strtoupper($label);
        $email = "tz-{$label}@canva.com";
        CanvaAccount::create(['email' => $email, 'password' => 'p', 'status' => 'ready']);

        $this->withHeaders($this->headers())->postJson('/api/internal/canva/reservations', [
            'order_id' => $orderId,
            'package_id' => '1m',
            'buyer_reference' => '7302' . ($label === 'wib' ? '1' : '2'),
        ])->assertStatus(201);

        $body = [
            'order_id' => $orderId,
            'payment_reference' => 'PAY-CNV-TZ-'.$label,
            'amount' => 15000,
            'currency' => 'IDR',
            'verified_at' => $input->toIso8601String(),
        ];

        $claim = $this->withHeaders($this->headers())->postJson('/api/internal/canva/claim', $body);
        $claim->assertStatus(200)->assertJson(['data' => ['status' => 'completed']]);

        $this->assertSame(
            $wib->getTimestamp(),
            Carbon::parse($claim->json('data.payment.verified_at'))->getTimestamp(),
            "claim mengembalikan instant yang bergeser untuk input {$label}"
        );

        $replay = $this->withHeaders($this->headers())->postJson('/api/internal/canva/claim', $body);
        $replay->assertStatus(200)->assertJson(['duplicated' => true]);
        $this->assertSame($claim->json('data.payment.verified_at'), $replay->json('data.payment.verified_at'));

        $inquiry = $this->withHeaders($this->headers())->getJson('/api/internal/canva/orders/'.$orderId);
        $inquiry->assertStatus(200)->assertJson([
            'data' => ['status' => 'completed', 'account' => ['email' => $email]],
        ]);
        $this->assertSame($claim->json('data.payment.verified_at'), $inquiry->json('data.payment.verified_at'));
        }
        }
        }
