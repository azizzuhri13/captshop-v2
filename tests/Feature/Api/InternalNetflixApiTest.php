<?php

namespace Tests\Feature\Api;

use App\Models\NetflixAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InternalNetflixApiTest extends TestCase
{
    use RefreshDatabase;

    protected function apiKey(): string
    {
        return config('services.internal_api_key');
    }

    public function test_rejects_unauthorized_request_without_key(): void
    {
        $response = $this->getJson('/api/internal/netflix/stock');
        $response->assertStatus(401);
    }

    public function test_rejects_invalid_api_key(): void
    {
        $response = $this->withHeaders(['X-Internal-Key' => 'wrong-key'])
            ->getJson('/api/internal/netflix/stock');

        $response->assertStatus(401);
    }

    public function test_stock_returns_accurate_counts(): void
    {
        NetflixAccount::create([
            'email' => 'acc1@gmail.com',
            'password' => 'pass1',
            'status' => 'ready',
        ]);
        NetflixAccount::create([
            'email' => 'acc2@gmail.com',
            'password' => 'pass2',
            'status' => 'sold',
        ]);

        $response = $this->withHeaders(['X-Internal-Key' => $this->apiKey()])
            ->getJson('/api/internal/netflix/stock');

        $response->assertStatus(200)
            ->assertJson([
                'status' => 'success',
                'ready_stock' => 1,
                'total_sold' => 1,
                'total_accounts' => 2,
            ]);
    }
}
