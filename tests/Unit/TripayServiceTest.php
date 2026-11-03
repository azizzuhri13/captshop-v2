<?php

namespace Tests\Unit;

use App\Models\PaymentChannel;
use App\Models\Transaction;
use App\Services\Payment\PaymentException;
use App\Services\Payment\TripayService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TripayServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Waktu dibekukan supaya perbandingan timestamp deterministik.
        Carbon::setTestNow(Carbon::parse('2026-11-03 10:00:00', 'UTC'));
        config()->set('payment.tripay.mode', 'sandbox');
        config()->set('payment.tripay.api_key', 'dev-api-key');
        config()->set('payment.tripay.private_key', 'dev-private-key');
        config()->set('payment.tripay.merchant_code', 'T12345');
        config()->set('payment.tripay.base_url', null);
        config()->set('payment.tripay.channels', [
            'QRIS' => 'QRIS',
            'BCA' => 'BCAVA',
            'OVO' => 'OVO',
        ]);
    }

    protected function tearDown(): void
    {
        // Jangan biarkan waktu beku merembes ke test lain di proses yang sama.
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function transaction(string $invoiceId = 'INV-1', int $total = 15000): Transaction
    {
        $transaction = new Transaction([
            'invoice_id' => $invoiceId,
            'payment_channel' => 'QRIS',
            'total' => (string) $total,
        ]);
        $transaction->expired_at = now()->addMinutes(30);

        return $transaction;
    }

    private function channel(string $code = 'QRIS'): PaymentChannel
    {
        return new PaymentChannel(['code' => $code, 'name' => $code, 'group' => 'QRIS']);
    }

    private function chargeResponse(array $overrides = []): array
    {
        return array_merge([
            'reference' => 'T1234567890',
            'merchant_ref' => 'INV-1',
            'payment_method' => 'QRIS',
            'payment_name' => 'QRIS',
            'amount' => 15000,
            'fee_customer' => 0,
            'status' => 'UNPAID',
            'expired_time' => now()->addMinutes(30)->timestamp,
            'qr_string' => '00020101021226',
            'qr_url' => 'https://tripay.co.id/qr/T1234567890.png',
            'checkout_url' => 'https://tripay.co.id/checkout/T1234567890',
        ], $overrides);
    }

    public function test_create_maps_internal_channel_to_tripay_method_and_sends_merchant_ref(): void
    {
        Http::fake(function ($request) {
            $body = json_decode($request->body(), true);
            $this->assertSame('BCAVA', $body['method']);
            $this->assertSame('INV-1', $body['merchant_ref']);
            $this->assertSame(15000, $body['amount']);
            $this->assertSame('Budi', $body['customer_name']);
            $this->assertSame('budi@example.com', $body['customer_email']);
            // SKU dari params menang atas invoice id: Tripay butuh kode produk.
            $this->assertSame('ml-10', $body['order_items'][0]['sku']);
            $this->assertSame(15000, $body['order_items'][0]['price']);
            // Dokumen Tripay: expired_time = unix timestamp batas bayar, bukan
            // durasi menit. Nilai menit (mis. 1440) akan dibaca sebagai 1970.
            $this->assertIsInt($body['expired_time']);
            $this->assertGreaterThan(now()->timestamp, $body['expired_time']);
            $this->assertSame(now()->addMinutes(30)->timestamp, $body['expired_time']);
            $this->assertSame(hash_hmac('sha256', 'T12345INV-115000', 'dev-private-key'), $body['signature']);

            return Http::response(['success' => true, 'data' => $this->chargeResponse(['payment_method' => 'BCAVA'])], 201);
        });

        $raw = app(TripayService::class)->createTransaction(
            $this->transaction(),
            ['name' => 'Voucher 10 Gems', 'price' => 15000, 'quantity' => 1],
            ['name' => 'Budi', 'phone' => '081234567890', 'email' => 'budi@example.com'],
            ['method' => 'BCA', 'sku' => 'ml-10'],
        );

        $normalized = app(TripayService::class)->normalizeCharge($raw, $this->transaction(), $this->channel('BCA'));

        $this->assertSame('INV-1', $normalized['merchant_ref']);
        $this->assertSame('T1234567890', $normalized['provider_ref']);
        $this->assertSame(15000, $normalized['merchant_amount']);
        $this->assertSame(15000, $normalized['payable_amount']);
        $this->assertSame('BCAVA', $normalized['channel']);
        $this->assertSame('pending', $normalized['status']);
        $this->assertSame('00020101021226', $normalized['qr_string']);
        $this->assertSame('https://tripay.co.id/qr/T1234567890.png', $normalized['qr_url']);
        $this->assertNull($normalized['va_number']);
    }

    public function test_qris_expiry_is_clamped_to_channel_sixty_minute_ceiling(): void
    {
        $transaction = $this->transaction();
        // Pesanan masih berlaku 3 jam, tetapi QRIS hanya menerima 60 menit.
        $transaction->expired_at = now()->addHours(3);

        Http::fake(function ($request) {
            $body = json_decode($request->body(), true);

            $this->assertSame('QRIS', $body['method']);
            $this->assertSame(now()->addHour()->timestamp, $body['expired_time']);

            return Http::response(['success' => true, 'data' => $this->chargeResponse()], 201);
        });

        app(TripayService::class)->createTransaction(
            $transaction,
            ['name' => 'Voucher 10 Gems', 'price' => 15000, 'quantity' => 1],
            ['name' => 'Budi', 'phone' => '081234567890', 'email' => 'budi@example.com'],
            ['method' => 'QRIS', 'sku' => 'ml-10'],
        );

        // Body diverifikasi di dalam fake: 60 menit dari sekarang.
    }

    public function test_past_expiry_never_sends_a_timestamp_in_the_past(): void
    {
        Http::fake();

        foreach ([now()->subMinute(), now()] as $expiredAt) {
            $transaction = $this->transaction();
            $transaction->expired_at = $expiredAt;

            try {
                app(TripayService::class)->createTransaction(
                    $transaction,
                    ['name' => 'Voucher 10 Gem', 'price' => 15000, 'quantity' => 1],
                    ['name' => 'Budi', 'phone' => '0812', 'email' => 'budi@example.com'],
                    ['method' => 'QRIS'],
                );
                $this->fail('Batas bayar yang sudah lewat tidak boleh menghasilkan instruksi bayar.');
            } catch (PaymentException $e) {
                $this->assertStringContainsString('sudah lewat', $e->getMessage());
            }
        }

        // Tidak ada request ke Tripay: timestamp masa lalu tidak pernah dikirim.
        Http::assertNothingSent();
    }

    public function test_create_requires_customer_email_before_calling_gateway(): void
    {
        Http::fake();

        $this->expectException(PaymentException::class);
        try {
            app(TripayService::class)->createTransaction(
                $this->transaction(),
                ['name' => 'Voucher', 'price' => 15000, 'quantity' => 1],
                ['name' => 'Budi', 'phone' => '0812', 'email' => null],
                ['method' => 'QRIS'],
            );
        } finally {
            Http::assertNothingSent();
        }
    }

    public function test_create_rejects_channel_without_verified_tripay_code(): void
    {
        Http::fake();

        $this->expectException(PaymentException::class);
        try {
            app(TripayService::class)->createTransaction(
                $this->transaction(),
                ['name' => 'Voucher', 'price' => 15000, 'quantity' => 1],
                ['name' => 'Budi', 'phone' => '0812', 'email' => 'budi@example.com'],
                ['method' => 'ASTRA'],
            );
        } finally {
            Http::assertNothingSent();
        }
    }

    public function test_normalize_charge_rejects_invoice_amount_and_channel_mismatch(): void
    {
        $service = app(TripayService::class);

        foreach ([
            ['merchant_ref' => 'INV-LAIN'],
            ['amount' => 14000],
            ['payment_method' => 'OVO'],
        ] as $override) {
            try {
                $service->normalizeCharge($this->chargeResponse($override), $this->transaction(), $this->channel('QRIS'));
                $this->fail('Respons tidak cocok harus ditolak: '.json_encode($override));
            } catch (PaymentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_normalize_detail_adds_customer_fee_to_payable_amount_and_maps_status(): void
    {
        $normalized = app(TripayService::class)->normalizeDetail(
            $this->chargeResponse(['fee_customer' => 500, 'status' => 'PAID', 'qr_string' => null, 'qr_url' => null]),
            $this->transaction(),
        );

        $this->assertSame(15000, $normalized['merchant_amount']);
        $this->assertSame(15500, $normalized['payable_amount']);
        $this->assertSame('paid', $normalized['status']);
        $this->assertNull($normalized['qr_string']);
    }

    public function test_webhook_verification_uses_raw_body_only_and_fails_closed_without_credentials(): void
    {
        $service = app(TripayService::class);
        $raw = '{"reference":"T1234567890","merchant_ref":"INV-1","status":"PAID"}';
        $signature = hash_hmac('sha256', $raw, 'dev-private-key');

        $this->assertTrue($service->verifyWebhook($raw, $signature));
        // Body yang diubah satu byte pun tidak boleh lolos (raw body, bukan array).
        $this->assertFalse($service->verifyWebhook($raw.' ', $signature));
        $this->assertFalse($service->verifyWebhook(str_replace('"PAID"', '"UNPAID"', $raw), $signature));
        $this->assertFalse($service->verifyWebhook($raw, ''));
        $this->assertFalse($service->verifySignature(json_decode($raw, true), $signature));

        config()->set('payment.tripay.private_key', null);
        config()->set('payment.tripay.api_key', null);
        $this->assertFalse($service->verifyWebhook($raw, $signature));
    }

    public function test_unpaid_and_unknown_statuses_never_map_to_paid(): void
    {
        $service = app(TripayService::class);

        $this->assertSame('pending', $service->mapStatus('UNPAID'));
        $this->assertSame('pending', $service->mapStatus('pending'));
        $this->assertSame('pending', $service->mapStatus('SESUATU_BARU'));
        $this->assertSame('paid', $service->mapStatus('PAID'));
        $this->assertSame('expired', $service->mapStatus('EXPIRED'));
        $this->assertSame('failed', $service->mapStatus('FAILED'));
        $this->assertSame('tripay', $service->gatewayName());
    }
}
