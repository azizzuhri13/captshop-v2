<?php

namespace Tests\Feature\Api;

use App\Models\NetflixAccount;
use App\Models\NetflixPartnerOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NetflixPartnerApiTest extends TestCase
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
        $this->getJson('/api/internal/netflix/packages')->assertStatus(401);
        $this->getJson('/api/internal/netflix/stock')->assertStatus(401);
        $this->postJson('/api/internal/netflix/reservations')->assertStatus(401);
        $this->postJson('/api/internal/netflix/claim')->assertStatus(401);
    }

    public function test_packages_returns_supported_package_catalog(): void
    {
        $response = $this->withHeaders($this->headers())->getJson('/api/internal/netflix/packages');

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
        $ready = NetflixAccount::create(['email' => 'ready@gmail.com', 'password' => 'p', 'status' => 'ready']);
        $staleAcc = NetflixAccount::create(['email' => 'stale@gmail.com', 'password' => 'p', 'status' => 'reserved']);

        NetflixPartnerOrder::create([
            'order_id' => 'STALE-001',
            'reservation_id' => 'uuid-stale-001',
            'payload_hash' => 'hash',
            'package_id' => '1p1u_1m',
            'buyer_reference' => '12345678',
            'status' => 'reserved',
            'netflix_account_id' => $staleAcc->id,
            'price' => 30000,
            'currency' => 'IDR',
            'reserved_until' => now()->subMinutes(5),
            'otp_token' => 'token-stale',
        ]);

        $response = $this->withHeaders($this->headers())->getJson('/api/internal/netflix/stock');

        $response->assertStatus(200)
            ->assertJson([
                'status' => 'success',
                'ready_stock' => 2,
            ]);

        $this->assertEquals('ready', $staleAcc->fresh()->status);
        $this->assertEquals('expired', NetflixPartnerOrder::where('order_id', 'STALE-001')->first()->status);
    }

    public function test_reserve_atomically_locks_account_and_clamps_ttl(): void
    {
        $acc = NetflixAccount::create([
            'email' => 'stock@gmail.com',
            'password' => 'p123',
            'profile_name' => 'Profile 1',
            'pin' => '1234',
            'status' => 'ready',
        ]);

        $response = $this->withHeaders($this->headers())->postJson('/api/internal/netflix/reservations', [
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
                    'package' => [
                        'id' => '1p1u_1m',
                    ],
                    'buyer_reference' => '987654321',
                    'buyer_username' => 'buyer_tele',
                    'ttl_minutes' => 30,
                    'stock_id' => $acc->id,
                ],
            ]);

        $this->assertEquals('reserved', $acc->fresh()->status);
        $this->assertEquals('telegram', $acc->fresh()->channel);
    }

    public function test_reserve_idempotent_returns_same_reservation_without_extending_ttl(): void
    {
        $acc = NetflixAccount::create(['email' => 'idemp@gmail.com', 'password' => 'p', 'status' => 'ready']);

        $payload = [
            'order_id' => 'MTH-IDEMP-001',
            'package_id' => '1p1u_1m',
            'buyer_reference' => '11223344',
            'buyer_username' => 'tele_user',
            'ttl_minutes' => 15,
        ];

        $first = $this->withHeaders($this->headers())->postJson('/api/internal/netflix/reservations', $payload);
        $first->assertStatus(201);
        $resUntil = $first->json('data.reserved_until');

        // Repeat with identical payload
        $second = $this->withHeaders($this->headers())->postJson('/api/internal/netflix/reservations', $payload);
        $second->assertStatus(200)
            ->assertJson(['duplicated' => true]);

        $this->assertSame($resUntil, $second->json('data.reserved_until'));
    }

    public function test_reserve_rejects_conflicting_payload_with_same_order_id(): void
    {
        NetflixAccount::create(['email' => 'conf@gmail.com', 'password' => 'p', 'status' => 'ready']);

        $payload = [
            'order_id' => 'MTH-CONF-001',
            'package_id' => '1p1u_1m',
            'buyer_reference' => '11223344',
        ];

        $this->withHeaders($this->headers())->postJson('/api/internal/netflix/reservations', $payload)->assertStatus(201);

        // Different buyer_reference with same order_id
        $conflict = $this->withHeaders($this->headers())->postJson('/api/internal/netflix/reservations', [
            'order_id' => 'MTH-CONF-001',
            'package_id' => '1p1u_1m',
            'buyer_reference' => '99887766',
        ]);

        $conflict->assertStatus(409)
            ->assertJson([
                'status' => 'error',
                'code' => 'IDEMPOTENCY_CONFLICT',
            ]);
    }

    public function test_claim_with_valid_payment_delivers_credentials_and_wib_dates(): void
    {
        $acc = NetflixAccount::create([
            'email' => 'claim@gmail.com',
            'password' => 'supersecret',
            'profile_name' => 'VIP 1',
            'pin' => '4321',
            'status' => 'ready',
        ]);

        $reserve = $this->withHeaders($this->headers())->postJson('/api/internal/netflix/reservations', [
            'order_id' => 'MTH-CLAIM-001',
            'package_id' => '1p1u_1m',
            'buyer_reference' => '55667788',
        ]);
        $reserve->assertStatus(201);
        $resId = $reserve->json('data.reservation_id');

        $claim = $this->withHeaders($this->headers())->postJson('/api/internal/netflix/claim', [
            'order_id' => 'MTH-CLAIM-001',
            'reservation_id' => $resId,
            'payment_reference' => 'PAY-QRIS-999888',
            'amount' => 30000,
            'currency' => 'IDR',
            'verified_at' => now()->toIso8601String(),
        ]);

        $claim->assertStatus(200)
            ->assertJson([
                'status' => 'success',
                'data' => [
                    'order_id' => 'MTH-CLAIM-001',
                    'status' => 'completed',
                    'account' => [
                        'email' => 'claim@gmail.com',
                        'password' => 'supersecret',
                        'profile' => 'VIP 1',
                        'pin' => '4321',
                    ],
                ],
            ]);

        $this->assertEquals('sold', $acc->fresh()->status);
        $this->assertEquals('telegram', $acc->fresh()->channel);
        $this->assertNotNull($claim->json('data.start_date'));
        $this->assertNotNull($claim->json('data.end_date'));
        $this->assertNotNull($claim->json('data.warranty_until'));
        $this->assertStringContainsString('/otp/', $claim->json('data.otp_url'));
    }

    public function test_claim_repeat_is_idempotent_and_does_not_shift_dates(): void
    {
        $acc = NetflixAccount::create(['email' => 'rep@gmail.com', 'password' => 'p', 'status' => 'ready']);

        $this->withHeaders($this->headers())->postJson('/api/internal/netflix/reservations', [
            'order_id' => 'MTH-REP-001',
            'package_id' => '1p1u_1m',
            'buyer_reference' => '998877',
        ]);

        $claim1 = $this->withHeaders($this->headers())->postJson('/api/internal/netflix/claim', [
            'order_id' => 'MTH-REP-001',
            'payment_reference' => 'PAY-001',
            'amount' => 30000,
            'currency' => 'IDR',
            'verified_at' => now()->toIso8601String(),
        ]);
        $claim1->assertStatus(200);
        $endDate1 = $claim1->json('data.end_date');

        // Repeat claim call
        $claim2 = $this->withHeaders($this->headers())->postJson('/api/internal/netflix/claim', [
            'order_id' => 'MTH-REP-001',
            'payment_reference' => 'PAY-001',
            'amount' => 30000,
            'currency' => 'IDR',
            'verified_at' => now()->toIso8601String(),
        ]);
        $claim2->assertStatus(200)
            ->assertJson(['duplicated' => true]);

        $this->assertSame($endDate1, $claim2->json('data.end_date'));
    }

    public function test_claim_late_payment_flags_review_required_and_refuses_auto_allocate(): void
    {
        $acc = NetflixAccount::create(['email' => 'late@gmail.com', 'password' => 'p', 'status' => 'ready']);

        $reserve = $this->withHeaders($this->headers())->postJson('/api/internal/netflix/reservations', [
            'order_id' => 'MTH-LATE-001',
            'package_id' => '1p1u_1m',
            'buyer_reference' => '121212',
            'ttl_minutes' => 5,
        ]);
        $reserve->assertStatus(201);

        // Simulate reservation expiration
        $order = NetflixPartnerOrder::where('order_id', 'MTH-LATE-001')->first();
        $order->update(['reserved_until' => now()->subMinutes(1)]);

        $claim = $this->withHeaders($this->headers())->postJson('/api/internal/netflix/claim', [
            'order_id' => 'MTH-LATE-001',
            'payment_reference' => 'PAY-LATE-999',
            'amount' => 30000,
            'currency' => 'IDR',
            'verified_at' => now()->toIso8601String(),
        ]);

        $claim->assertStatus(409)
            ->assertJson([
                'status' => 'error',
                'code' => 'PAYMENT_REVIEW_REQUIRED',
            ]);

        $this->assertEquals('review_required', $order->fresh()->status);
    }

    public function test_cancel_reservation_releases_ready_stock(): void
    {
        $acc = NetflixAccount::create(['email' => 'canc@gmail.com', 'password' => 'p', 'status' => 'ready']);

        $this->withHeaders($this->headers())->postJson('/api/internal/netflix/reservations', [
            'order_id' => 'MTH-CANC-001',
            'package_id' => '1p1u_1m',
            'buyer_reference' => '334455',
        ]);
        $this->assertEquals('reserved', $acc->fresh()->status);

        $cancel = $this->withHeaders($this->headers())->postJson('/api/internal/netflix/cancel-reservation', [
            'order_id' => 'MTH-CANC-001',
        ]);
        $cancel->assertStatus(200);

        $this->assertEquals('ready', $acc->fresh()->status);
        $this->assertNull($acc->fresh()->channel);
        $this->assertEquals('cancelled', NetflixPartnerOrder::where('order_id', 'MTH-CANC-001')->first()->status);

        // Repeat cancel is safe and idempotent
        $this->withHeaders($this->headers())->postJson('/api/internal/netflix/cancel-reservation', [
            'order_id' => 'MTH-CANC-001',
        ])->assertStatus(200);
    }

    public function test_cancel_refuses_completed_order(): void
    {
        NetflixAccount::create(['email' => 'done@gmail.com', 'password' => 'p', 'status' => 'ready']);

        $this->withHeaders($this->headers())->postJson('/api/internal/netflix/reservations', [
            'order_id' => 'MTH-DONE-001',
            'package_id' => '1p1u_1m',
            'buyer_reference' => '778899',
        ]);

        $this->withHeaders($this->headers())->postJson('/api/internal/netflix/claim', [
            'order_id' => 'MTH-DONE-001',
            'payment_reference' => 'PAY-DONE',
            'amount' => 30000,
            'currency' => 'IDR',
            'verified_at' => now()->toIso8601String(),
        ]);

        $cancel = $this->withHeaders($this->headers())->postJson('/api/internal/netflix/cancel-reservation', [
            'order_id' => 'MTH-DONE-001',
        ]);

        $cancel->assertStatus(409)
            ->assertJson([
                'status' => 'error',
                'code' => 'ORDER_ALREADY_COMPLETED',
            ]);
    }

    public function test_order_inquiry_recovers_state(): void
    {
        $acc = NetflixAccount::create(['email' => 'inq@gmail.com', 'password' => 'p', 'status' => 'ready']);

        $this->withHeaders($this->headers())->postJson('/api/internal/netflix/reservations', [
            'order_id' => 'MTH-INQ-001',
            'package_id' => '1p1u_1m',
            'buyer_reference' => '123123',
        ]);

        $inquiry = $this->withHeaders($this->headers())->getJson('/api/internal/netflix/orders/MTH-INQ-001');

        $inquiry->assertStatus(200)
            ->assertJson([
                'status' => 'success',
                'data' => [
                    'order_id' => 'MTH-INQ-001',
                    'status' => 'reserved',
                ],
            ]);
    }

    public function test_claim_rejects_duplicate_payment_reference(): void
    {
        $acc1 = NetflixAccount::create(['email' => 'a1@gmail.com', 'password' => 'p', 'status' => 'ready']);
        $acc2 = NetflixAccount::create(['email' => 'a2@gmail.com', 'password' => 'p', 'status' => 'ready']);

        // Order 1
        $this->withHeaders($this->headers())->postJson('/api/internal/netflix/reservations', [
            'order_id' => 'ORD-001', 'package_id' => '1p1u_1m', 'buyer_reference' => '111',
        ]);
        $this->withHeaders($this->headers())->postJson('/api/internal/netflix/claim', [
            'order_id' => 'ORD-001', 'payment_reference' => 'QRIS-SHARED-REF', 'amount' => 30000, 'currency' => 'IDR', 'verified_at' => now()->toIso8601String(),
        ])->assertStatus(200);

        // Order 2 tries to use the same payment_reference
        $this->withHeaders($this->headers())->postJson('/api/internal/netflix/reservations', [
            'order_id' => 'ORD-002', 'package_id' => '1p1u_1m', 'buyer_reference' => '222',
        ]);
        $dup = $this->withHeaders($this->headers())->postJson('/api/internal/netflix/claim', [
            'order_id' => 'ORD-002', 'payment_reference' => 'QRIS-SHARED-REF', 'amount' => 30000, 'currency' => 'IDR', 'verified_at' => now()->toIso8601String(),
        ]);

        $dup->assertStatus(409)
            ->assertJson([
                'status' => 'error',
                'code' => 'PAYMENT_REFERENCE_DUPLICATED',
            ]);
    }

    public function test_claim_rejects_mismatched_amount_or_currency(): void
    {
        NetflixAccount::create(['email' => 'mismatch@gmail.com', 'password' => 'p', 'status' => 'ready']);

        $this->withHeaders($this->headers())->postJson('/api/internal/netflix/reservations', [
            'order_id' => 'ORD-MISMATCH', 'package_id' => '1p1u_1m', 'buyer_reference' => '333',
        ]);

        // Wrong amount
        $this->withHeaders($this->headers())->postJson('/api/internal/netflix/claim', [
            'order_id' => 'ORD-MISMATCH', 'payment_reference' => 'REF-1', 'amount' => 25000, 'currency' => 'IDR', 'verified_at' => now()->toIso8601String(),
        ])->assertStatus(422)
          ->assertJson(['code' => 'PAYMENT_AMOUNT_MISMATCH']);

        // Wrong currency
        $this->withHeaders($this->headers())->postJson('/api/internal/netflix/claim', [
            'order_id' => 'ORD-MISMATCH', 'payment_reference' => 'REF-1', 'amount' => 30000, 'currency' => 'USD', 'verified_at' => now()->toIso8601String(),
        ])->assertStatus(422)
          ->assertJson(['code' => 'PAYMENT_CURRENCY_MISMATCH']);
    }

    public function test_buyer_otp_endpoint_authorizes_by_token(): void
    {
        $acc = NetflixAccount::create([
            'email' => 'buyer.otp@gmail.com',
            'password' => 'p',
            'profile_name' => 'Profile 1',
            'pin' => '7788',
            'status' => 'ready',
        ]);

        $reserve = $this->withHeaders($this->headers())->postJson('/api/internal/netflix/reservations', [
            'order_id' => 'MTH-OTP-001',
            'package_id' => '1p1u_1m',
            'buyer_reference' => '654321',
        ]);

        $this->withHeaders($this->headers())->postJson('/api/internal/netflix/claim', [
            'order_id' => 'MTH-OTP-001',
            'payment_reference' => 'PAY-OTP',
            'amount' => 30000,
            'currency' => 'IDR',
            'verified_at' => now()->toIso8601String(),
        ]);

        $order = NetflixPartnerOrder::where('order_id', 'MTH-OTP-001')->first();

        // Valid token gets clean isolated view
        $response = $this->getJson('/api/internal/netflix/otp/'.$order->otp_token);
        $response->assertStatus(200)
            ->assertJson([
                'status' => 'success',
                'data' => [
                    'email' => 'buyer.otp@gmail.com',
                    'profile' => 'Profile 1',
                    'otp' => '7788',
                ],
            ]);

        // Invalid token gets 404
        $this->getJson('/api/internal/netflix/otp/invalid-token')->assertStatus(404);
    }
}
