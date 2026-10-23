<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Supplier / Provider
    |--------------------------------------------------------------------------
    |
    | Supplier default untuk fulfillment pesanan (top-up game, pulsa, PPOB).
    | Arsitektur multi-supplier dengan fallback otomatis menyusul di fase lanjutan.
    |
    */

    'default' => env('SUPPLIER', 'digiflazz'),

    'digiflazz' => [
        'username' => env('DIGIFLAZZ_USERNAME'),
        'api_key' => env('DIGIFLAZZ_API_KEY'),
        'base_url' => env('DIGIFLAZZ_BASE_URL', 'https://api.digiflazz.com/v1'),
        'webhook_secret' => env('DIGIFLAZZ_WEBHOOK_SECRET'),

        // Mode pengembangan DigiFlazz: transaksi tidak benar-benar dieksekusi.
        'testing' => env('DIGIFLAZZ_TESTING', false),

        /*
        |----------------------------------------------------------------------
        | Pricelist & kesegaran data ketersediaan
        |----------------------------------------------------------------------
        | Cache pricelist disimpan bersama metadata `fetched_at`. Saat API gagal
        | (mis. RC 83 rate limit), cache hanya boleh dipakai selama umurnya belum
        | melewati `max_age_minutes`; lewat dari itu sinkronisasi dibatalkan dan
        | admin diberi alert — data basi tidak boleh mengubah status aktif katalog.
        |
        | `max_deactivation_ratio`/`_floor` adalah pengaman bila pricelist datang
        | terpotong: nonaktifkan massal di atas ambang ini ditahan, bukan diterapkan.
        */
        'pricelist' => [
            'cache_path' => env('DIGIFLAZZ_PRICELIST_CACHE', storage_path('app/digiflazz/pricelist.json')),
            'max_age_minutes' => (int) env('DIGIFLAZZ_PRICELIST_MAX_AGE_MINUTES', 60),
            'max_deactivation_ratio' => (float) env('DIGIFLAZZ_MAX_DEACTIVATION_RATIO', 0.25),
            'max_deactivation_floor' => (int) env('DIGIFLAZZ_MAX_DEACTIVATION_FLOOR', 5),
        ],

        /*
        |----------------------------------------------------------------------
        | Seller yang diblokir permanen
        |----------------------------------------------------------------------
        | Nama seller upstream (dipisah koma) yang produknya tidak boleh
        | diaktifkan lagi oleh sinkronisasi mana pun, walau kedua flag supplier
        | true. Dibandingkan setelah trim + rapatkan spasi + uppercase.
        */
        'blocked_sellers' => env('DIGIFLAZZ_BLOCKED_SELLERS', 'CUAN AE'),
    ],

    'tokovoucher' => [
        'member_code' => env('TOKOVOUCHER_MEMBER_CODE'),
        'secret_key' => env('TOKOVOUCHER_SECRET_KEY'),
        'signature' => env('TOKOVOUCHER_SIGNATURE'),
        'base_url' => env('TOKOVOUCHER_BASE_URL', 'https://api.tokovoucher.net'),
    ],

];
