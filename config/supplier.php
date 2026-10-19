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
    ],

    'tokovoucher' => [
        'member_code' => env('TOKOVOUCHER_MEMBER_CODE'),
        'secret_key' => env('TOKOVOUCHER_SECRET_KEY'),
        'signature' => env('TOKOVOUCHER_SIGNATURE'),
        'base_url' => env('TOKOVOUCHER_BASE_URL', 'https://api.tokovoucher.net'),
    ],

];
