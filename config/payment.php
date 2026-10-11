<?php

return [
    // Belibayar adalah satu-satunya gateway pembayaran untuk pesanan baru.
    'default' => 'belibayar',
    'belibayar' => [
        'mode' => env('BELIBAYAR_MODE', 'sandbox'),
        'base_url' => env('BELIBAYAR_BASE_URL', 'https://api.belibayar.id'),
        'api_key' => env('BELIBAYAR_API_KEY'),
        'secret_key' => env('BELIBAYAR_SECRET_KEY'),
        'webhook_secret' => env('BELIBAYAR_WEBHOOK_SECRET'),
        'merchant_id' => env('BELIBAYAR_MERCHANT_ID'),
    ],
];
