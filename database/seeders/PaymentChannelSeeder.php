<?php

namespace Database\Seeders;

use App\Models\PaymentChannel;
use App\Support\PaymentGateway;
use Illuminate\Database\Seeder;

class PaymentChannelSeeder extends Seeder
{
    /**
     * Metadata tampilan per kode channel Tripay.
     *
     * Kode internal HARUS kunci yang sudah terdaftar di
     * config('payment.tripay.channels'); di luar itu tidak dibuat sama sekali.
     * Fee sengaja 0 karena fee merchant Tripay belum dikonfirmasi — jangan
     * menebak angka. Aktivasi juga diserahkan ke admin, jadi baris baru selalu
     * dibuat nonaktif.
     *
     * @var array<string, array{0: string, 1: string}>
     */
    protected const TRIPAY_DISPLAY = [
        'QRIS' => ['QRIS', 'QRIS'],
        'BCA' => ['BCA Virtual Account', 'Virtual Account'],
        'BNI' => ['BNI Virtual Account', 'Virtual Account'],
        'BRI' => ['BRI Virtual Account', 'Virtual Account'],
        'MANDIRI' => ['Mandiri Virtual Account', 'Virtual Account'],
        'PERMATA' => ['Permata Virtual Account', 'Virtual Account'],
        'BSI' => ['BSI Virtual Account', 'Virtual Account'],
        'MUAMALAT' => ['Muamalat Virtual Account', 'Virtual Account'],
        'CIMB' => ['CIMB Virtual Account', 'Virtual Account'],
        'BNC' => ['BNC Virtual Account', 'Virtual Account'],
        'MAYBANK' => ['Maybank Virtual Account', 'Virtual Account'],
        'SINARMAS' => ['Sinarmas Virtual Account', 'Virtual Account'],
        'INDOMARET' => ['Indomaret', 'Retail'],
        'ALFAMART' => ['Alfamart', 'Retail'],
        'OVO' => ['OVO', 'E-Wallet'],
        'DANA' => ['DANA', 'E-Wallet'],
        'SPAY' => ['ShopeePay', 'E-Wallet'],
    ];

    /**
     * Run the database seeds.
     */
    public function run(): void
    {
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

        $tripayCodes = $this->tripayCodes();

        foreach ($tripayCodes as $index => $code) {
            [$name, $group] = self::TRIPAY_DISPLAY[$code];
            $channels[] = ['tripay', $code, $name, $group, '0', '0', $index + 1];
        }

        // Nonaktifkan hanya baris yang memang tidak lagi didukung: gateway di
        // luar daftar resmi, atau kode Tripay yang tidak ada di katalog.
        // Baris sah yang pernah diaktifkan admin TIDAK disentuh agar seeder
        // bisa diulang tanpa menghapus aktivasi/fee hasil pengaturan admin.
        PaymentChannel::query()
            ->whereNotIn('gateway', PaymentGateway::SUPPORTED)
            ->where('is_active', true)
            ->update(['is_active' => false]);

        PaymentChannel::query()
            ->where('gateway', PaymentGateway::TRIPAY)
            ->whereNotIn('code', $tripayCodes)
            ->where('is_active', true)
            ->update(['is_active' => false]);

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

    /**
     * Kode channel Tripay internal yang boleh dibuat barisnya: irisan antara
     * peta resmi di config dan metadata tampilan.
     *
     * @return list<string>
     */
    protected function tripayCodes(): array
    {
        $map = config('payment.tripay.channels', []);
        $map = is_array($map) ? $map : [];

        $codes = [];

        foreach (array_keys(self::TRIPAY_DISPLAY) as $code) {
            if (array_key_exists($code, $map)) {
                $codes[] = $code;
            }
        }

        return $codes;
    }
}
