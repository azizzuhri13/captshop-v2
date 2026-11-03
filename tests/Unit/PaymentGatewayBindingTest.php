<?php

namespace Tests\Unit;

use App\Services\Payment\BelibayarService;
use App\Services\Payment\PaymentGatewayInterface;
use App\Services\Payment\TripayService;
use Tests\TestCase;

/**
 * Kontrak pemilihan gateway: default produksi tetap belibayar dan tidak ada
 * jalur otomatis dari env ke Tripay.
 */
class PaymentGatewayBindingTest extends TestCase
{
    public function test_default_configuration_resolves_belibayar(): void
    {
        $this->assertSame('belibayar', config('payment.default'));
        $this->assertInstanceOf(BelibayarService::class, app(PaymentGatewayInterface::class));
    }

    public function test_tripay_requires_both_explicit_default_and_enabled_flag(): void
    {
        config()->set('payment.default', 'tripay');

        // enabled tetap false → tidak boleh pindah gateway.
        $this->assertInstanceOf(BelibayarService::class, app(PaymentGatewayInterface::class));

        config()->set('payment.tripay.enabled', true);
        $this->assertInstanceOf(TripayService::class, app(PaymentGatewayInterface::class));
    }

    public function test_unknown_gateway_value_falls_back_to_belibayar(): void
    {
        config()->set('payment.default', 'midtrans');

        $this->assertInstanceOf(BelibayarService::class, app(PaymentGatewayInterface::class));
    }
}
