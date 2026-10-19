<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'fonnte' => [
        'token' => env('FONNTE_TOKEN'),
        'base_url' => env('FONNTE_BASE_URL', 'https://api.fonnte.com'),
    ],

    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'redirect' => env('GOOGLE_REDIRECT_URI', 'https://topup.kidbomba.space/auth/google/callback'),
    ],

    'telegram' => [
        'bot_token' => env('TELEGRAM_BOT_TOKEN'),
        'chat_id' => env('TELEGRAM_ADMIN_CHAT_ID'),
    ],

    'internal_api_key' => env('INTERNAL_API_KEY'),

    /*
    | Kontrak API enterprise untuk bot partner Telegram (Murthehelp).
    | shortcut: harga & durasi paket disalin dari product_items Netflix saat
    | kontrak dibuat; upgrade ke pembacaan product_items bila admin harus bisa
    | mengubah harga paket bot sendiri dari panel.
    */
    'netflix' => [
        'currency' => 'IDR',
        'ttl' => ['min' => 5, 'max' => 30, 'default' => 15],

        'packages' => [
            '1p1u_1m' => [
                'name' => '1P1U 1 Bulan (1 Profil 1 User)',
                'duration_days' => 30,
                'warranty_days' => 30,
                'price' => 30000,
                'currency' => 'IDR',
                'expiry_rules' => 'Berjalan sejak start_date (Asia/Jakarta), berakhir pada end_date; 1 profil 1 user, profil dikunci PIN.',
            ],
        ],
    ],

    'disney' => [
        'currency' => 'IDR',
        'ttl' => ['min' => 5, 'max' => 30, 'default' => 15],

        'packages' => [
            '1p1u_1m' => [
                'name' => 'Disney+ Hotstar 1 Bulan',
                'duration_days' => 30,
                'warranty_days' => 30,
                'price' => 25000,
                'currency' => 'IDR',
                'expiry_rules' => 'Berjalan sejak start_date (Asia/Jakarta), berakhir pada end_date; garansi 30 hari.',
            ],
        ],
    ],

    'canva' => [
        'currency' => 'IDR',
        'ttl' => ['min' => 5, 'max' => 30, 'default' => 15],

        'packages' => [
            '1m' => [
                'name' => 'Canva Pro 1 Bulan',
                'duration_days' => 30,
                'warranty_days' => 30,
                'price' => 15000,
                'currency' => 'IDR',
                'expiry_rules' => 'Berjalan sejak start_date (Asia/Jakarta), berakhir pada end_date; garansi 30 hari.',
            ],
        ],
    ],

];
