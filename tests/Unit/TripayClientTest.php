<?php

namespace Tests\Unit;

use App\Services\Payment\PaymentException;
use App\Services\Payment\TripayClient;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TripayClientTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config()->set('payment.tripay.mode', 'sandbox');
        config()->set('payment.tripay.api_key', 'dev-api-key');
        config()->set('payment.tripay.private_key', 'dev-private-key');
        config()->set('payment.tripay.merchant_code', 'T12345');
        config()->set('payment.tripay.base_url', null);
    }

    public function test_create_sends_bearer_and_signature_over_merchant_code_ref_amount(): void
    {
        Http::fake(function ($request) {
            $this->assertSame('https://tripay.co.id/api-sandbox/transaction/create', $request->url());
            $this->assertSame('Bearer dev-api-key', $request->header('Authorization')[0]);

            $body = json_decode($request->body(), true);
            $this->assertSame('INV-1', $body['merchant_ref']);
            $this->assertSame(15000, $body['amount']);
            $this->assertSame(
                hash_hmac('sha256', 'T12345INV-115000', 'dev-private-key'),
                $body['signature']
            );
            $this->assertSame($body['signature'], $request->header('X-Signature')[0]);

            return Http::response(['success' => true, 'data' => ['reference' => 'T99', 'merchant_ref' => 'INV-1', 'status' => 'UNPAID']], 201);
        });

        $data = app(TripayClient::class)->createTransaction([
            'method' => 'QRIS',
            'merchant_ref' => 'INV-1',
            'amount' => 15000,
        ]);

        $this->assertSame('T99', $data['reference']);
    }

    public function test_detail_queries_reference_and_rejects_malformed_reference_without_request(): void
    {
        Http::fake(function ($request) {
            $this->assertSame('https://tripay.co.id/api-sandbox/transaction/detail?reference=T1234567890', $request->url());

            return Http::response(['success' => true, 'data' => ['reference' => 'T1234567890', 'status' => 'PAID']]);
        });

        $this->assertSame('PAID', app(TripayClient::class)->transactionDetail('T1234567890')['status']);

        Http::fake();
        $this->expectException(PaymentException::class);
        try {
            app(TripayClient::class)->transactionDetail('T123/../../etc/passwd');
        } finally {
            Http::assertNothingSent();
        }
    }

    public function test_missing_credentials_fail_closed_without_sending_request(): void
    {
        config()->set('payment.tripay.api_key', null);
        Http::fake();

        $this->expectException(PaymentException::class);
        try {
            app(TripayClient::class)->createTransaction([
                'method' => 'QRIS',
                'merchant_ref' => 'INV-1',
                'amount' => 15000,
            ]);
        } finally {
            Http::assertNothingSent();
        }
    }

    public function test_unknown_mode_or_foreign_base_url_is_refused_before_network(): void
    {
        config()->set('payment.tripay.base_url', 'https://attacker.example/api');
        Http::fake();

        try {
            app(TripayClient::class)->createTransaction(['method' => 'QRIS', 'merchant_ref' => 'INV-1', 'amount' => 15000]);
            $this->fail('Base URL asing harus ditolak.');
        } catch (PaymentException $e) {
            $this->assertStringNotContainsString('dev-api-key', $e->getMessage());
        } finally {
            Http::assertNothingSent();
        }

        config()->set('payment.tripay.base_url', null);
        config()->set('payment.tripay.mode', 'staging');

        $this->expectException(PaymentException::class);
        app(TripayClient::class)->createTransaction(['method' => 'QRIS', 'merchant_ref' => 'INV-1', 'amount' => 15000]);
    }

    public function test_rejected_response_surfaces_provider_message_without_leaking_key(): void
    {
        Http::fake([
            '*' => Http::response(['success' => false, 'message' => 'Signature tidak valid'], 401),
        ]);

        try {
            app(TripayClient::class)->createTransaction(['method' => 'QRIS', 'merchant_ref' => 'INV-1', 'amount' => 15000]);
            $this->fail('Respons gagal harus jadi PaymentException.');
        } catch (PaymentException $e) {
            $this->assertStringContainsString('Signature tidak valid', $e->getMessage());
            $this->assertStringNotContainsString('dev-api-key', $e->getMessage());
            $this->assertStringNotContainsString('dev-private-key', $e->getMessage());
        }
    }
}
