<?php

namespace Tests\Unit;

use App\Services\Payment\BelibayarClient;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class BelibayarClientTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config()->set('payment.belibayar.api_key', 'byr_test_example');
        config()->set('payment.belibayar.secret_key', 'sandbox-secret');
        config()->set('payment.belibayar.mode', 'sandbox');
        config()->set('payment.belibayar.base_url', 'https://api.belibayar.id');
    }

    public function test_charge_signs_exact_json_body_in_sandbox(): void
    {
        Http::fake(function ($request) {
            $this->assertSame('https://api.belibayar.id/direct/v1/sandbox/payment/charge', $request->url());
            $this->assertSame('byr_test_example', $request->header('X-Api-Key')[0]);
            $this->assertSame(hash_hmac('sha256', $request->body(), 'sandbox-secret'), $request->header('X-Signature')[0]);
            $this->assertSame('QRIS', json_decode($request->body(), true)['reference']);
            return Http::response(['success' => true, 'data' => ['reference' => 'QRIS', 'status' => 'pending']], 201);
        });
        $response = app(BelibayarClient::class)->charge(['reference' => 'QRIS', 'amount' => 15000, 'pay_method' => ['method' => 'qris']]);
        $this->assertSame('pending', $response['status']);
    }

    public function test_status_uses_legacy_timestamp_canonical_not_path(): void
    {
        Http::fake(function ($request) {
            $this->assertSame('https://api.belibayar.id/direct/v1/sandbox/payment/status/INV-1', $request->url());
            $timestamp = $request->header('X-Timestamp')[0];
            $this->assertSame(hash_hmac('sha256', ':'.$timestamp, 'sandbox-secret'), $request->header('X-Signature')[0]);
            return Http::response(['success' => true, 'data' => ['reference' => 'INV-1', 'status' => 'paid']]);
        });
        $this->assertSame('paid', app(BelibayarClient::class)->status('INV-1')['status']);
    }

    public function test_charge_auto_switches_to_live_path_when_live_key_provided(): void
    {
        config()->set('payment.belibayar.api_key', 'byr_live_production_key');
        config()->set('payment.belibayar.secret_key', 'live-secret');
        config()->set('payment.belibayar.mode', 'sandbox'); // even if env was sandbox

        Http::fake(function ($request) {
            $this->assertSame('https://api.belibayar.id/direct/v1/payment/charge', $request->url());
            return Http::response(['success' => true, 'data' => ['reference' => 'QRIS', 'status' => 'pending']], 201);
        });

        $response = app(BelibayarClient::class)->charge(['reference' => 'QRIS', 'amount' => 15000, 'pay_method' => ['method' => 'qris']]);
        $this->assertSame('pending', $response['status']);
    }
}
