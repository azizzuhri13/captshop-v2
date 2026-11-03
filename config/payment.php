<?php

return [
    // Fallback gateway untuk pesanan BARU bila admin belum pernah memakai
    // saklar gateway di panel (site setting `payment_gateway`). Saklar admin
    // selalu menang atas nilai ini. Belibayar tetap default.
    //
    // JANGAN mengubahnya menjadi env(): beberapa host lama masih menyimpan
    // PAYMENT_GATEWAY=tripay sehingga env akan mengaktifkan Tripay tanpa
    // sengaja. Tripay hanya bisa aktif dari saklar admin (yang memerlukan
    // kredensial + channel + callback siap) atau dari jalur literal lama di
    // bawah ini, bukan dari env.
    'default' => 'belibayar',
    'belibayar' => [
        'mode' => env('BELIBAYAR_MODE', 'sandbox'),
        'base_url' => env('BELIBAYAR_BASE_URL', 'https://api.belibayar.id'),
        'api_key' => env('BELIBAYAR_API_KEY'),
        'secret_key' => env('BELIBAYAR_SECRET_KEY'),
        'webhook_secret' => env('BELIBAYAR_WEBHOOK_SECRET'),
        'merchant_id' => env('BELIBAYAR_MERCHANT_ID'),
    ],
    // Tripay (Closed Payment). Jalur lama: 'default' diubah eksplisit DAN
    // 'enabled' true. Jalur operasional yang dipakai: saklar admin
    // (site setting `payment_gateway`) dengan pemeriksaan readiness.
    'tripay' => [
        'enabled' => (bool) env('TRIPAY_ENABLED', false),
        // Mode dipilih admin di panel (site setting `tripay_mode`). Default
        // sandbox dan sengaja literal: env TRIPAY_MODE lama tidak boleh
        // diam-diam memilih live tanpa terlihat di panel.
        'mode' => 'sandbox',

        // Kredensial dikelola admin lewat site_settings (lihat
        // SettingController + Credentials). Tidak ada fallback env TRIPAY_*
        // agar kredensial admin tidak tercampur sisa nilai .env host lama.
        'api_key' => null,
        'private_key' => null,
        'merchant_code' => null,
        // URL callback yang didaftarkan di dashboard Tripay (Closed Payment:
        // satu URL per merchant, bukan per transaksi). Rutenya POST
        // /webhook/tripay di routes/webhook.php. Nilai ini tidak dikirim ke
        // API; hanya dipakai sebagai rujukan operasional & assertion test.
        // HOST WAJIB captshop.id — bukan next.captshop.id (itu app Next.js
        // terpisah dan tidak punya route ini). Sengaja literal, bukan env():
        // env TRIPAY_* lama di host produksi tidak boleh membelokkan callback.
        'callback_url' => 'https://captshop.id/webhook/tripay',

        // TIDAK ADA 'base_url' di sini. TripayClient menurunkan origin dari
        // mode (sandbox/live) dan mengabaikan TRIPAY_BASE_URL lama yang di
        // produksi berisi origin live — membacanya akan mematikan mode
        // sandbox dan membuka jalan request ke host lain.
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
