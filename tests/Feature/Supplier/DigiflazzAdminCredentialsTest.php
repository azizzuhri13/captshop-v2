<?php

namespace Tests\Feature\Supplier;

use App\Models\SiteSetting;
use App\Services\Supplier\DigiflazzService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class DigiflazzAdminCredentialsTest extends TestCase
{
    use RefreshDatabase;

    public function test_balance_request_accepts_credentials_saved_in_admin_when_env_is_empty(): void
    {
        config()->set('supplier.digiflazz.username', null);
        config()->set('supplier.digiflazz.api_key', null);
        SiteSetting::set('digiflazz_username', 'admin-user');
        SiteSetting::set('digiflazz_api_key', 'admin-key');
        Http::fake(['api.digiflazz.com/v1/cek-saldo' => Http::response(['data' => ['deposit' => 12345]], 200)]);

        $result = app(DigiflazzService::class)->checkBalance();

        $this->assertSame(12345.0, $result['balance']);
        Http::assertSent(fn ($request) => $request->url() === 'https://api.digiflazz.com/v1/cek-saldo'
            && $request['username'] === 'admin-user'
            && $request['sign'] === md5('admin-user'.'admin-key'.'depo')
            && $request['cmd'] === 'deposit');
    }
}
