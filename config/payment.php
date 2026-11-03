<?php

return [
    // Belibayar adalah satu-satunya gateway pembayaran untuk pesanan baru.
    // JANGAN mengubah nilai literal ini menjadi env(): produksi masih memakai
    // PAYMENT_GATEWAY=tripay di beberapa host lama dan gateway itu belum
    // divalidasi untuk alur order/webhook.
    'default' => 'belibayar',
    'belibayar' => [
        'mode' => env('BELIBAYAR_MODE', 'sandbox'),
        'base_url' => env('BELIBAYAR_BASE_URL', 'https://api.belibayar.id'),
        'api_key' => env('BELIBAYAR_API_KEY'),
        'secret_key' => env('BELIBAYAR_SECRET_KEY'),
        'webhook_secret' => env('BELIBAYAR_WEBHOOK_SECRET'),
        'merchant_id' => env('BELIBAYAR_MERCHANT_ID'),
    ],
    // Tripay (Closed Payment). Belum dipakai order flow; binding hanya aktif
    // bila 'default' diubah eksplisit DAN 'enabled' true.
    'tripay' => [
        'enabled' => (bool) env('TRIPAY_ENABLED', false),
        'mode' => env('TRIPAY_MODE', 'sandbox'),
        'api_key' => env('TRIPAY_API_KEY'),
        'private_key' => env('TRIPAY_PRIVATE_KEY'),
        'merchant_code' => env('TRIPAY_MERCHANT_CODE'),
        // Hanya origin resmi Tripay yang diterima TripayClient; override ini
        // ada agar config cache tetap terbaca, bukan untuk host lain.
        'base_url' => env('TRIPAY_BASE_URL'),
        // Channel internal → kode metode Tripay. Kode yang tidak terdaftar
        // ditolak (fail-closed); jangan menebak kode yang belum diverifikasi.
        'channels' => [
            'QRIS' => 'QRIS',
            'BCA' => 'BCAVA',
            'BNI' => 'BNIVA',
            'BRI' => 'BRIVA',
            'MANDIRI' => 'MANDIRIVA',
            'PERMATA' => 'PERMATAVA',
            'BSI' => 'BSIVA',
            'MUAMALAT' => 'MUAMALATVA',
            'CIMB' => 'CIMBVA',
            'BNC' => 'BNCVA',
            'MAYBANK' => 'MAYBANKVA',
            'SINARMAS' => 'SINARMASVA',
            'INDOMARET' => 'INDOMARET',
            'ALFAMART' => 'ALFAMART',
            'OVO' => 'OVO',
            'DANA' => 'DANA',
            'SPAY' => 'SHOPEEPAY',
        ],
    ],
];
