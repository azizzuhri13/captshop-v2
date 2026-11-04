<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Kontak Customer Service (default publik)
    |--------------------------------------------------------------------------
    |
    | Nilai di sini adalah fallback yang tampil di storefront bila
    | site_settings (contact_whatsapp / contact_instagram / contact_email)
    | masih kosong. Admin tetap dapat menimpanya dari panel Pengaturan.
    | Nomor WhatsApp ditulis tanpa "+" dan tanpa spasi; helper
    | App\Support\SupportContact yang menormalkan dan memvalidasinya.
    |
    */

    'contact' => [
        'whatsapp' => env('SITE_CONTACT_WHATSAPP', '6282260677717'),

        'instagram' => env(
            'SITE_CONTACT_INSTAGRAM',
            'https://www.instagram.com/captshop.id?cplk=b3RheWxxaHk1dWcw'
        ),

        'email' => env('SITE_CONTACT_EMAIL'),
    ],

];
