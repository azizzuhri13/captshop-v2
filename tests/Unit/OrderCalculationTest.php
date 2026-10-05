<?php

namespace Tests\Unit;

use App\Models\PaymentChannel;
use PHPUnit\Framework\TestCase;

class OrderCalculationTest extends TestCase
{
    public function test_calculate_fee_flat_plus_percent(): void
    {
        $channel = new PaymentChannel([
            'flat_fee' => '1000',
            'percent_fee' => '2',
        ]);

        $this->assertSame(1200.0, $channel->calculateFee(10000));
    }

    public function test_calculate_fee_zero_fee(): void
    {
        $channel = new PaymentChannel([
            'flat_fee' => '0',
            'percent_fee' => '0',
        ]);

        $this->assertSame(0.0, $channel->calculateFee(999999));
    }

    public function test_invoice_amount_total_and_profit_math(): void
    {
        // Simulasi kalkulasi OrderService::place tanpa DB.
        $sellPrice = 15000.0;
        $buyPrice = 13000.0;
        $flatFee = 0.0;
        $percentFee = 0.70;

        $amount = $sellPrice;
        $fee = round($flatFee + ($amount * $percentFee / 100), 2);
        $total = round($amount + $fee, 2);
        $profit = round($amount - $buyPrice, 2);

        $this->assertSame(105.0, $fee);
        $this->assertSame(15105.0, $total);
        $this->assertSame(2000.0, $profit);
    }
}
