<?php

namespace Tests\Unit;

use App\Services\Payment\TripayClient;
use App\Services\Payment\TripayService;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class TripaySignatureTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Config::set('payment.tripay.private_key', 'test-private-key');
    }

    public function test_callback_signature_matches_tripay_documented_algorithm(): void
    {
        $service = new TripayService(new TripayClient('test-api-key', 'https://tripay.example.com'));

        $payload = [
            'merchant_ref' => 'INV-20260908-ABC123',
            'status' => 'PAID',
            'amount' => 16000,
        ];

        $expected = hash_hmac('sha256', 'INV-20260908-ABC123PAID16000', 'test-private-key');

        $this->assertSame($expected, $service->calculateSignature($payload));
    }

    public function test_verify_signature_accepts_valid_and_rejects_invalid(): void
    {
        $service = new TripayService(new TripayClient('test-api-key', 'https://tripay.example.com'));

        $payload = ['merchant_ref' => 'INV-1', 'status' => 'PAID', 'amount' => 50000];

        $valid = hash_hmac('sha256', 'INV-1PAID50000', 'test-private-key');

        $this->assertTrue($service->verifySignature($payload, $valid));
        $this->assertFalse($service->verifySignature($payload, 'deadbeef'));
        $this->assertFalse($service->verifySignature(['status' => 'PAID'], $valid));
    }

    public function test_gateway_status_maps_to_internal_status(): void
    {
        $service = new TripayService(new TripayClient('test-api-key', 'https://tripay.example.com'));

        $this->assertSame('pending', $service->mapStatus('UNPAID'));
        $this->assertSame('pending', $service->mapStatus('pending'));
        $this->assertSame('paid', $service->mapStatus('PAID'));
        $this->assertSame('expired', $service->mapStatus('EXPIRED'));
        $this->assertSame('failed', $service->mapStatus('FAILED'));
        $this->assertSame('failed', $service->mapStatus('REFUND'));
        $this->assertSame('pending', $service->mapStatus('SOMETHING_NEW'));
    }
}
