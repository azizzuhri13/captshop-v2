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
        // Hide obsolete gateway channels without deleting historical metadata.
        PaymentChannel::query()->where('gateway', '!=', 'belibayar')->update(['is_active' => false]);
        $channels = [];

        // Belibayar codes must match its Direct API.
        // Fees are intentionally zero until the merchant's negotiated fees are confirmed.
        $belibayar = [
            ['BCA', 'BCA Virtual Account', 'Virtual Account'],
            ['BNI', 'BNI Virtual Account', 'Virtual Account'],
            ['BRI', 'BRI Virtual Account', 'Virtual Account'],
            ['MANDIRI', 'Mandiri Virtual Account', 'Virtual Account'],
            ['PERMATA', 'Permata Virtual Account', 'Virtual Account'],
            ['BSI', 'BSI Virtual Account', 'Virtual Account'],
            ['CIMB', 'CIMB Virtual Account', 'Virtual Account'],
            ['DANA', 'DANA', 'E-Wallet'],
            ['OVO', 'OVO', 'E-Wallet'],
            ['SPAY', 'ShopeePay', 'E-Wallet'],
            ['INDOMARET', 'Indomaret', 'Retail'],
            ['ALFAMART', 'Alfamart', 'Retail'],
            ['QRIS', 'QRIS', 'QRIS'],
        ];
        foreach ($belibayar as $index => [$code, $name, $group]) {
            $channels[] = ['belibayar', $code, $name, $group, '0', '0', $index + 1];
        }

        foreach ($channels as [$gateway, $code, $name, $group, $flatFee, $percentFee, $sortOrder]) {
            // Jangan reset aktivasi atau fee yang telah diatur admin ketika seeder diulang.
            PaymentChannel::query()->firstOrCreate(
                ['gateway' => $gateway, 'code' => $code],
                [
                    'name' => $name,
                    'group' => $group,
                    'flat_fee' => $flatFee,
                    'percent_fee' => $percentFee,
                    'is_active' => false,
                    'sort_order' => $sortOrder,
                ]
            );
        }
    }
}
