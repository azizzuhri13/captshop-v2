<?php

namespace Tests\Feature\Api;

use App\Models\DisneyAccount;
use App\Models\DisneyPartnerOrder;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DisneyPartnerApiTest extends TestCase
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
        $this->getJson('/api/internal/disney/packages')->assertStatus(401);
        $this->getJson('/api/internal/disney/stock')->assertStatus(401);
        $this->postJson('/api/internal/disney/reservations')->assertStatus(401);
        $this->postJson('/api/internal/disney/claim')->assertStatus(401);
    }

    public function test_packages_returns_supported_package_catalog(): void
    {
        $response = $this->withHeaders($this->headers())->getJson('/api/internal/disney/packages');

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
        $ready = DisneyAccount::create(['email' => 'ready@gmail.com', 'password' => 'p', 'status' => 'ready']);
        $staleAcc = DisneyAccount::create(['email' => 'stale@gmail.com', 'password' => 'p', 'status' => 'reserved']);

        DisneyPartnerOrder::create([
            'order_id' => 'STALE-001',
            'reservation_id' => 'uuid-stale-001',
            'payload_hash' => 'hash',
            'package_id' => '1p1u_1m',
            'buyer_reference' => '12345678',
            'status' => 'reserved',
            'disney_account_id' => $staleAcc->id,
            'price' => 25000,
            'currency' => 'IDR',
            'reserved_until' => now()->subMinutes(5),
            'otp_token' => 'token-stale',
        ]);

        $response = $this->withHeaders($this->headers())->getJson('/api/internal/disney/stock');

        $response->assertStatus(200)
            ->assertJson([
                'status' => 'success',
                'ready_stock' => 2,
            ]);

        $this->assertEquals('ready', $staleAcc->fresh()->status);
    }

    public function test_reserve_atomically_locks_account_and_clamps_ttl(): void
    {
        $acc = DisneyAccount::create([
            'email' => 'stock@disney.com',
            'password' => 'p123',
            'profile_name' => 'Profile 1',
            'pin' => '1234',
            'status' => 'ready',
        ]);

        $response = $this->withHeaders($this->headers())->postJson('/api/internal/disney/reservations', [
            'order_id' => 'MTH-ORDER-101',
            'package_id' => '1p1u_1m',
            'buyer_reference' => '987654321',
            'buyer_username' => 'buyer_tele',
            'ttl_minutes' => 60, // clamped to 30
        ]);

        $response->assertStatus(201)
            ->assertJson([
                'status' => 'success',
                'data' => [
                    'order_id' => 'MTH-ORDER-101',
                    'status' => 'reserved',
                    'ttl_minutes' => 30,
                    'stock_id' => $acc->id,
                    'profile_id' => $acc->id,
                ],
            ]);

        $this->assertEquals('reserved', $acc->fresh()->status);
    }

    public function test_reserve_idempotent_returns_same_reservation(): void
    {
        $acc = DisneyAccount::create(['email' => 'idemp@gmail.com', 'password' => 'p', 'status' => 'ready']);

        $payload = [
            'order_id' => 'MTH-IDEMP-001',
            'package_id' => '1p1u_1m',
            'buyer_reference' => '99887766',
        ];

        $first = $this->withHeaders($this->headers())->postJson('/api/internal/disney/reservations', $payload);
        $first->assertStatus(201);
        $resId = $first->json('data.reservation_id');
        $until = $first->json('data.reserved_until');

        $second = $this->withHeaders($this->headers())->postJson('/api/internal/disney/reservations', $payload);
        $second->assertStatus(200)
            ->assertJson([
                'status' => 'success',
                'duplicated' => true,
                'data' => [
                    'order_id' => 'MTH-IDEMP-001',
                    'reservation_id' => $resId,
                    'reserved_until' => $until,
                ],
            ]);
    }

    public function test_claim_with_valid_payment_delivers_credentials(): void
    {
        $acc = DisneyAccount::create([
            'email' => 'claim@disney.com',
            'password' => 'supersecret',
            'profile_name' => 'Profile A',
            'pin' => '9999',
            'status' => 'ready',
        ]);

        $this->withHeaders($this->headers())->postJson('/api/internal/disney/reservations', [
            'order_id' => 'MTH-CLAIM-001',
            'package_id' => '1p1u_1m',
            'buyer_reference' => '12345678',
        ]);

        $response = $this->withHeaders($this->headers())->postJson('/api/internal/disney/claim', [
            'order_id' => 'MTH-CLAIM-001',
            'payment_reference' => 'QRIS-REF-998877',
            'amount' => 25000,
            'currency' => 'IDR',
            'verified_at' => now()->toIso8601String(),
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'status' => 'success',
                'data' => [
                    'order_id' => 'MTH-CLAIM-001',
                    'status' => 'completed',
                    'account' => [
                        'email' => 'claim@disney.com',
                        'password' => 'supersecret',
                        'profile' => 'Profile A',
                        'pin' => '9999',
                    ],
                ],
            ]);

        $this->assertEquals('sold', $acc->fresh()->status);
        $this->assertEquals('telegram', $acc->fresh()->channel);
    }

    public function test_order_inquiry_recovers_state(): void
    {
        $acc = DisneyAccount::create(['email' => 'inq@disney.com', 'password' => 'p', 'status' => 'ready']);

        $this->withHeaders($this->headers())->postJson('/api/internal/disney/reservations', [
            'order_id' => 'MTH-INQ-001',
            'package_id' => '1p1u_1m',
            'buyer_reference' => '12345678',
        ]);

        $res = $this->withHeaders($this->headers())->getJson('/api/internal/disney/orders/MTH-INQ-001');
        $res->assertStatus(200)
        ->assertJson([
            'status' => 'success',
            'data' => [
                'order_id' => 'MTH-INQ-001',
                'status' => 'reserved',
            ],
        ]);
        }

        public function test_claim_verified_at_round_trips_wib_utc_replay_and_get(): void
        {
        // 06:30 WIB = 23:30 UTC hari sebelumnya: sekaligus menguji rollover tanggal.
        $wib = Carbon::parse('2026-10-22 06:30:00', 'Asia/Jakarta');

        foreach (['wib' => $wib, 'utc' => $wib->copy()->setTimezone('UTC')] as $label => $input) {
        $orderId = 'MTH-TZ-'.strtoupper($label);
        $email = "tz-{$label}@disney.com";
        DisneyAccount::create(['email' => $email, 'password' => 'p', 'status' => 'ready']);

        $this->withHeaders($this->headers())->postJson('/api/internal/disney/reservations', [
            'order_id' => $orderId,
            'package_id' => '1p1u_1m',
            'buyer_reference' => '7303'.$label,
        ])->assertStatus(201);

        $body = [
            'order_id' => $orderId,
            'payment_reference' => 'PAY-MTH-TZ-'.$label,
            'amount' => 25000,
            'currency' => 'IDR',
            'verified_at' => $input->toIso8601String(),
        ];

        $claim = $this->withHeaders($this->headers())->postJson('/api/internal/disney/claim', $body);
        $claim->assertStatus(200)->assertJson(['data' => ['status' => 'completed']]);

        $this->assertSame(
            $wib->getTimestamp(),
            Carbon::parse($claim->json('data.payment.verified_at'))->getTimestamp(),
            "claim mengembalikan instant yang bergeser untuk input {$label}"
        );

        $replay = $this->withHeaders($this->headers())->postJson('/api/internal/disney/claim', $body);
        $replay->assertStatus(200)->assertJson(['duplicated' => true]);
        $this->assertSame($claim->json('data.payment.verified_at'), $replay->json('data.payment.verified_at'));

        $inquiry = $this->withHeaders($this->headers())->getJson('/api/internal/disney/orders/'.$orderId);
        $inquiry->assertStatus(200)->assertJson([
            'data' => ['status' => 'completed', 'account' => ['email' => $email]],
        ]);
        $this->assertSame($claim->json('data.payment.verified_at'), $inquiry->json('data.payment.verified_at'));
        }
        }
        }
