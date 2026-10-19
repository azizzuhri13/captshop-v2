<?php

namespace Tests\Unit;

use App\Models\Transaction;
use App\Services\Payment\BelibayarClient;
use App\Services\Payment\BelibayarService;
use Mockery;
use Tests\TestCase;

class BelibayarServiceTest extends TestCase
{
    public function test_qris_charge_uses_dynamic_qr_and_returns_instruction_fields(): void
    {
        $transaction = new Transaction(['invoice_id' => 'INV-1', 'payment_channel' => 'QRIS', 'total' => '15105']);
        $transaction->expired_at = now()->addHour();
        $client = Mockery::mock(BelibayarClient::class);
        $client->shouldReceive('charge')->once()->withArgs(function ($body) {
            return $body['reference'] === 'INV-1' && $body['amount'] === 15105
                && $body['pay_method'] === ['method' => 'qris'] && $body['static_qr'] === false;
        })->andReturn(['reference'=>'INV-1', 'transaction_id'=>'byrtrx_1', 'status'=>'pending', 'qr_content'=>'000201', 'qr_url'=>'https://link.belibayar.id/test.png', 'expired_at'=>now()->addMinutes(45)->timestamp]);
        $result = (new BelibayarService($client))->createTransaction($transaction, [], ['name'=>'Budi', 'email'=>'budi@example.com'], []);
        $this->assertSame('000201', $result['qr_content']);
        $this->assertSame('byrtrx_1', $result['transaction_id']);
    }

    public function test_retail_uses_virtual_account_method_as_documented(): void
    {
        $transaction = new Transaction(['invoice_id' => 'INV-RETAIL', 'payment_channel' => 'INDOMARET', 'total' => '100000']);
        $client = Mockery::mock(BelibayarClient::class);
        $client->shouldReceive('charge')->once()->withArgs(function ($body) {
            return $body['pay_method'] === ['method' => 'virtual_account', 'channel' => 'INDOMARET'];
        })->andReturn(['reference' => 'INV-RETAIL']);

        (new BelibayarService($client))->createTransaction($transaction, [], ['name' => 'Budi', 'email' => 'budi@example.com']);
    }

    public function test_webhook_signature_is_raw_body_hmac_and_status_cancelled_is_expired(): void
    {
        config()->set('payment.belibayar.webhook_secret', 'test-webhook-secret');
        $service = new BelibayarService(Mockery::mock(BelibayarClient::class));
        $raw = "{\"status\":\"paid\", \"reference\":\"INV-1\"}";
        $this->assertTrue($service->verifyWebhook($raw, hash_hmac('sha256', $raw, 'test-webhook-secret')));
        $this->assertFalse($service->verifyWebhook(str_replace(' ', '', $raw), hash_hmac('sha256', $raw, 'test-webhook-secret')));
        $this->assertSame('expired', $service->mapStatus('cancelled'));
    }
}
