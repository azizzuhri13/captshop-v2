<?php

namespace Database\Seeders;

use App\Models\PaymentChannel;
use Illuminate\Database\Seeder;

class PaymentChannelSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $channels = [
            // [gateway, code, name, group, flat_fee, percent_fee, sort_order]
            ['tripay', 'BRIVA', 'BRI Virtual Account', 'Virtual Account', '0', '0.70', 1],
            ['tripay', 'BCAVA', 'BCA Virtual Account', 'Virtual Account', '0', '0.70', 2],
            ['tripay', 'BNIVA', 'BNI Virtual Account', 'Virtual Account', '0', '0.70', 3],
            ['tripay', 'MANDIRIVA', 'Mandiri Virtual Account', 'Virtual Account', '0', '0.70', 4],
            ['tripay', 'PERMATAVA', 'Permata Virtual Account', 'Virtual Account', '0', '0.70', 5],
            ['tripay', 'CIMBVA', 'CIMB Virtual Account', 'Virtual Account', '0', '0.70', 6],
            ['tripay', 'DANA', 'DANA', 'E-Wallet', '0', '0.70', 10],
            ['tripay', 'OVO', 'OVO', 'E-Wallet', '0', '0.70', 11],
            ['tripay', 'GOPAY', 'GoPay', 'E-Wallet', '0', '0.70', 12],
            ['tripay', 'SHOPEEPAY', 'ShopeePay', 'E-Wallet', '0', '0.70', 13],
            ['tripay', 'LINKAJA', 'LinkAja', 'E-Wallet', '0', '0.70', 14],
            ['tripay', 'ALFAMART', 'Alfamart', 'Retail', '0', '0.70', 20],
            ['tripay', 'INDOMARET', 'Indomaret', 'Retail', '0', '0.70', 21],
            ['tripay', 'QRIS', 'QRIS', 'QRIS', '0', '0.70', 30],
        ];

        foreach ($channels as [$gateway, $code, $name, $group, $flatFee, $percentFee, $sortOrder]) {
            PaymentChannel::updateOrCreate(
                ['gateway' => $gateway, 'code' => $code],
                [
                    'name' => $name,
                    'group' => $group,
                    'flat_fee' => $flatFee,
                    'percent_fee' => $percentFee,
                    'is_active' => true,
                    'sort_order' => $sortOrder,
                ]
            );
        }
    }
}
