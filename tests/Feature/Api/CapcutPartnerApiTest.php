<?php

namespace Tests\Feature\Api;

use App\Models\CapcutAccount;
use App\Models\CapcutPartnerOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CapcutPartnerApiTest extends TestCase
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
        $this->getJson('/api/internal/capcut/packages')->assertStatus(401);
        $this->getJson('/api/internal/capcut/stock')->assertStatus(401);
        $this->postJson('/api/internal/capcut/reservations')->assertStatus(401);
        $this->postJson('/api/internal/capcut/claim')->assertStatus(401);
    }

    public function test_packages_returns_supported_package_catalog(): void
    {
        $response = $this->withHeaders($this->headers())->getJson('/api/internal/capcut/packages');

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
        $ready = CapcutAccount::create(['email' => 'ready@gmail.com', 'password' => 'p', 'status' => 'ready']);
        $staleAcc = CapcutAccount::create(['email' => 'stale@gmail.com', 'password' => 'p', 'status' => 'reserved']);

        CapcutPartnerOrder::create([
            'order_id' => 'STALE-CPCT-001',
            'reservation_id' => 'uuid-stale-cpct',
            'payload_hash' => 'hash',
            'package_id' => '1m',
            'buyer_reference' => '12345678',
            'status' => 'reserved',
            'capcut_account_id' => $staleAcc->id,
            'price' => 25000,
            'currency' => 'IDR',
            'reserved_until' => now()->subMinutes(5),
            'otp_token' => 'token-stale',
        ]);

        $response = $this->withHeaders($this->headers())->getJson('/api/internal/capcut/stock');

        $response->assertStatus(200)
            ->assertJson([
                'status' => 'success',
                'ready_stock' => 2,
            ]);

        $this->assertEquals('ready', $staleAcc->fresh()->status);
    }

    public function test_reserve_atomically_locks_account_and_clamps_ttl(): void
    {
        $acc = CapcutAccount::create([
            'email' => 'stock@capcut.com',
            'password' => 'p123',
            'status' => 'ready',
        ]);

        $response = $this->withHeaders($this->headers())->postJson('/api/internal/capcut/reservations', [
            'order_id' => 'CPCT-ORDER-101',
            'package_id' => '1m',
            'buyer_reference' => '987654321',
            'buyer_username' => 'buyer_tele',
            'ttl_minutes' => 60, // clamped to 30
        ]);

        $response->assertStatus(201)
            ->assertJson([
                'status' => 'success',
                'data' => [
                    'order_id' => 'CPCT-ORDER-101',
                    'status' => 'reserved',
                    'ttl_minutes' => 30,
                    'stock_id' => $acc->id,
                ],
            ]);

        $this->assertEquals('reserved', $acc->fresh()->status);
    }

    public function test_claim_with_valid_payment_delivers_credentials(): void
    {
        $acc = CapcutAccount::create([
            'email' => 'claim@capcut.com',
            'password' => 'capcutpro99',
            'status' => 'ready',
        ]);

        $this->withHeaders($this->headers())->postJson('/api/internal/capcut/reservations', [
            'order_id' => 'CPCT-CLAIM-001',
            'package_id' => '1m',
            'buyer_reference' => '12345678',
        ]);

        $response = $this->withHeaders($this->headers())->postJson('/api/internal/capcut/claim', [
            'order_id' => 'CPCT-CLAIM-001',
            'payment_reference' => 'QRIS-CPCT-998877',
            'amount' => 25000,
            'currency' => 'IDR',
            'verified_at' => now()->toIso8601String(),
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'status' => 'success',
                'data' => [
                    'order_id' => 'CPCT-CLAIM-001',
                    'status' => 'completed',
                    'account' => [
                        'email' => 'claim@capcut.com',
                        'password' => 'capcutpro99',
                    ],
                ],
            ]);

        $this->assertEquals('sold', $acc->fresh()->status);
        $this->assertEquals('telegram', $acc->fresh()->channel);
    }

    public function test_order_inquiry_recovers_state(): void
    {
        $acc = CapcutAccount::create(['email' => 'inq@capcut.com', 'password' => 'p', 'status' => 'ready']);

        $this->withHeaders($this->headers())->postJson('/api/internal/capcut/reservations', [
            'order_id' => 'CPCT-INQ-001',
            'package_id' => '1m',
            'buyer_reference' => '12345678',
        ]);

        $res = $this->withHeaders($this->headers())->getJson('/api/internal/capcut/orders/CPCT-INQ-001');
        $res->assertStatus(200)
            ->assertJson([
                'status' => 'success',
                'data' => [
                    'order_id' => 'CPCT-INQ-001',
                    'status' => 'reserved',
                ],
            ]);
    }
}
