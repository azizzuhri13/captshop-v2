<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductItem;
use Illuminate\Database\Seeder;

class GameProductSeeder extends Seeder
{
    /**
     * Data dummy produk game populer + item nominalnya.
     *
     * Idempotent: dicocokkan lewat slug produk dan supplier_item_code item,
     * jadi aman dijalankan berulang tanpa menghasilkan duplikat.
     *
     * Harga beli ≈ 92% harga jual (margin dummy ±8%). Angka harga mengikuti
     * kisaran pasar, bukan angka resmi dari publisher.
     */
    public function run(): void
    {
        $topupCategory = Category::where('slug', 'topup')->first();

        if (! $topupCategory) {
            $this->command->warn('Kategori "topup" tidak ditemukan. Seeder dilewati.');

            return;
        }

        foreach ($this->games() as $order => $game) {
            $product = Product::updateOrCreate(
                ['slug' => $game['slug']],
                [
                    'category_id' => $topupCategory->id,
                    'name' => $game['name'],
                    'code' => $game['code'],
                    'description' => $game['description'],
                    'instruction' => $game['instruction'],
                    'input_fields' => $game['fields'],
                    'supplier_type' => 'digiflazz',
                    'supplier_code' => $game['supplier_code'],
                    'is_popular' => $game['popular'] ?? false,
                    'is_active' => true,
                    'sort_order' => $order + 1,
                ]
            );

            foreach ($game['items'] as $index => $item) {
                [$name, $sellPrice, $group] = $item;

                ProductItem::updateOrCreate(
                    [
                        'product_id' => $product->id,
                        'supplier_item_code' => $game['sku'].'-'.($index + 1),
                    ],
                    [
                        'name' => $name,
                        'group' => $group,
                        'buy_price' => (int) round($sellPrice * 0.92),
                        'sell_price' => $sellPrice,
                        'is_active' => true,
                        'sort_order' => $index,
                    ]
                );
            }

            $this->command->info(sprintf(
                '%-26s %2d item',
                $game['name'],
                count($game['items'])
            ));
        }
    }

    /**
     * Field form dinamis per game, mengikuti konvensi: user_id selalu ada,
     * ditambah server/zone hanya untuk game yang memang memerlukannya.
     */
    private function fieldUserId(string $placeholder = 'Masukkan User ID'): array
    {
        return [
            'name' => 'user_id',
            'label' => 'User ID',
            'type' => 'text',
            'required' => true,
            'placeholder' => $placeholder,
        ];
    }

    private function fieldServer(string $label = 'Server', string $placeholder = 'Masukkan Server ID'): array
    {
        return [
            'name' => 'server_id',
            'label' => $label,
            'type' => 'text',
            'required' => true,
            'placeholder' => $placeholder,
        ];
    }

    private function fieldZone(): array
    {
        return [
            'name' => 'zone_id',
            'label' => 'Zone ID',
            'type' => 'text',
            'required' => true,
            'placeholder' => 'Masukkan Zone ID',
        ];
    }

    private function fieldEmail(): array
    {
        return [
            'name' => 'email',
            'label' => 'Email Akun',
            'type' => 'email',
            'required' => false,
            'placeholder' => 'nama@email.com',
        ];
    }

    private function games(): array
    {
        return [
            [
                'slug' => 'free-fire',
                'name' => 'Free Fire',
                'code' => 'FF',
                'sku' => 'ff',
                'supplier_code' => 'free-fire',
                'popular' => true,
                'description' => 'Top up Diamond Free Fire instan 24 jam. Cukup masukkan User ID, diamond langsung masuk ke akunmu.',
                'instruction' => "1. Masukkan User ID Free Fire kamu\n2. Pilih nominal Diamond\n3. Pilih metode pembayaran\n4. Diamond otomatis masuk setelah pembayaran berhasil",
                'fields' => [$this->fieldUserId()],
                'items' => [
                    ['5 Diamonds', 1500, null],
                    ['12 Diamonds', 3300, null],
                    ['50 Diamonds', 13000, null],
                    ['70 Diamonds', 18000, null],
                    ['140 Diamonds', 36000, null],
                    ['355 Diamonds', 89000, null],
                    ['720 Diamonds', 178000, null],
                    ['1080 Diamonds', 265000, null],
                    ['2200 Diamonds', 530000, null],
                    ['Membership Mingguan', 75000, 'Membership'],
                    ['Membership Bulanan', 280000, 'Membership'],
                ],
            ],
            [
                'slug' => 'pubg-mobile',
                'name' => 'PUBG Mobile',
                'code' => 'PUBGM',
                'sku' => 'pubgm',
                'supplier_code' => 'pubg-mobile',
                'popular' => true,
                'description' => 'Beli UC PUBG Mobile dengan proses cepat dan aman. UC masuk langsung ke akunmu tanpa perlu login.',
                'instruction' => "1. Masukkan User ID PUBG Mobile kamu\n2. Pilih nominal UC\n3. Pilih metode pembayaran\n4. UC otomatis masuk setelah pembayaran berhasil",
                'fields' => [$this->fieldUserId(), $this->fieldZone()],
                'items' => [
                    ['60 UC', 16000, null],
                    ['325 UC', 79000, null],
                    ['660 UC', 155000, null],
                    ['1800 UC', 395000, null],
                    ['3850 UC', 790000, null],
                    ['8100 UC', 1580000, null],
                    ['Royale Pass Elite', 158000, 'Royale Pass'],
                    ['Royale Pass Elite Plus', 395000, 'Royale Pass'],
                ],
            ],
            [
                'slug' => 'genshin-impact',
                'name' => 'Genshin Impact',
                'code' => 'GI',
                'sku' => 'gi',
                'supplier_code' => 'genshin-impact',
                'popular' => true,
                'description' => 'Top up Genesis Crystals Genshin Impact untuk semua server. Proses instan dan aman.',
                'instruction' => "1. Masukkan UID Genshin Impact kamu\n2. Pilih server (Asia, Amerika, Eropa, TW/HK/MO)\n3. Pilih nominal Genesis Crystals\n4. Kristal masuk setelah pembayaran berhasil",
                'fields' => [$this->fieldUserId('Masukkan UID'), $this->fieldServer('Server', 'Asia / Amerika / Eropa')],
                'items' => [
                    ['60 Genesis Crystals', 16000, null],
                    ['300 + 30 Genesis Crystals', 79000, null],
                    ['980 + 110 Genesis Crystals', 249000, null],
                    ['1980 + 260 Genesis Crystals', 479000, null],
                    ['3280 + 600 Genesis Crystals', 799000, null],
                    ['6480 + 1600 Genesis Crystals', 1590000, null],
                    ['Blessing of the Welkin Moon', 79000, 'Paket Spesial'],
                    ['Gnostic Hymn (Battle Pass)', 159000, 'Paket Spesial'],
                ],
            ],
            [
                'slug' => 'honor-of-kings',
                'name' => 'Honor of Kings',
                'code' => 'HOK',
                'sku' => 'hok',
                'supplier_code' => 'honor-of-kings',
                'popular' => true,
                'description' => 'Top up Tokens Honor of Kings cepat dan murah. Cukup User ID, token langsung masuk.',
                'instruction' => "1. Masukkan User ID Honor of Kings kamu\n2. Pilih nominal Tokens\n3. Pilih metode pembayaran\n4. Token masuk setelah pembayaran berhasil",
                'fields' => [$this->fieldUserId()],
                'items' => [
                    ['16 Tokens', 5000, null],
                    ['80 Tokens', 24000, null],
                    ['240 Tokens', 71000, null],
                    ['400 Tokens', 118000, null],
                    ['800 Tokens', 235000, null],
                    ['1600 Tokens', 470000, null],
                    ['Weekly Card', 32000, 'Kartu'],
                    ['Monthly Card', 95000, 'Kartu'],
                ],
            ],
            [
                'slug' => 'roblox',
                'name' => 'Roblox',
                'code' => 'RBX',
                'sku' => 'rbx',
                'supplier_code' => 'roblox',
                'popular' => true,
                'description' => 'Beli Robux untuk dipakai di semua game Roblox. Masukkan username, Robux langsung dikirim.',
                'instruction' => "1. Masukkan username Roblox kamu (bukan email)\n2. Pilih nominal Robux\n3. Pilih metode pembayaran\n4. Robux masuk setelah pembayaran berhasil",
                'fields' => [
                    ['name' => 'username', 'label' => 'Username Roblox', 'type' => 'text', 'required' => true, 'placeholder' => 'Masukkan username'],
                ],
                'items' => [
                    ['80 Robux', 16000, null],
                    ['400 Robux', 79000, null],
                    ['800 Robux', 155000, null],
                    ['1700 Robux', 320000, null],
                    ['4500 Robux', 790000, null],
                    ['10000 Robux', 1650000, null],
                ],
            ],
            [
                'slug' => 'valorant',
                'name' => 'Valorant',
                'code' => 'VAL',
                'sku' => 'val',
                'supplier_code' => 'valorant',
                'popular' => false,
                'description' => 'Top up Valorant Points (VP) untuk skin dan battle pass. Proses cepat dan aman.',
                'instruction' => "1. Masukkan Riot ID kamu (format: Nama#Tag)\n2. Pilih nominal Valorant Points\n3. Pilih metode pembayaran\n4. VP masuk setelah pembayaran berhasil",
                'fields' => [
                    ['name' => 'riot_id', 'label' => 'Riot ID', 'type' => 'text', 'required' => true, 'placeholder' => 'Contoh: Player#ID1'],
                ],
                'items' => [
                    ['125 VP', 16000, null],
                    ['420 VP', 50000, null],
                    ['700 VP', 79000, null],
                    ['1375 VP', 150000, null],
                    ['2400 VP', 260000, null],
                    ['4000 VP', 420000, null],
                ],
            ],
            [
                'slug' => 'steam-wallet',
                'name' => 'Steam Wallet',
                'code' => 'STEAM',
                'sku' => 'steam',
                'supplier_code' => 'steam-wallet',
                'popular' => false,
                'description' => 'Isi saldo Steam Wallet untuk beli game dan item di Steam. Kode dikirim setelah pembayaran.',
                'instruction' => "1. Masukkan email kamu untuk pengiriman kode\n2. Pilih nominal saldo\n3. Pilih metode pembayaran\n4. Kode voucher dikirim setelah pembayaran berhasil",
                'fields' => [$this->fieldEmail()],
                'items' => [
                    ['Steam Wallet IDR 12.000', 15000, null],
                    ['Steam Wallet IDR 45.000', 50000, null],
                    ['Steam Wallet IDR 60.000', 65000, null],
                    ['Steam Wallet IDR 90.000', 95000, null],
                    ['Steam Wallet IDR 120.000', 127000, null],
                    ['Steam Wallet IDR 250.000', 260000, null],
                    ['Steam Wallet IDR 400.000', 415000, null],
                ],
            ],
            [
                'slug' => 'google-play',
                'name' => 'Google Play',
                'code' => 'GPLAY',
                'sku' => 'gplay',
                'supplier_code' => 'google-play',
                'popular' => true,
                'description' => 'Voucher Google Play untuk beli aplikasi, game, dan item dalam game Android.',
                'instruction' => "1. Masukkan email kamu untuk pengiriman kode\n2. Pilih nominal voucher\n3. Pilih metode pembayaran\n4. Kode voucher dikirim setelah pembayaran berhasil",
                'fields' => [$this->fieldEmail()],
                'items' => [
                    ['Google Play IDR 5.000', 7000, null],
                    ['Google Play IDR 20.000', 23000, null],
                    ['Google Play IDR 50.000', 54000, null],
                    ['Google Play IDR 100.000', 105000, null],
                    ['Google Play IDR 150.000', 157000, null],
                    ['Google Play IDR 300.000', 312000, null],
                ],
            ],
        ];
    }
}