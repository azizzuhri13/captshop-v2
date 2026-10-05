<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    public function createApplication()
    {
        // Shell tertentu mewariskan variabel .env ke environment OS, dan nilai
        // pada $_SERVER menang atas <env> phpunit.xml. Tanpa pembersihan ini,
        // test berjalan dengan konfigurasi lokal (APP_ENV=local, MySQL asli,
        // session driver database) yang membuat semua POST gagal 419 dan
        // RefreshDatabase menjalankan migrate:fresh pada database developer.
        // Variabel DB_* dan APP_KEY yang dibersihkan akan diisi ulang oleh
        // Dotenv dari file .env, sisanya diambil dari phpunit.xml.
        foreach ([
            'APP_ENV',
            'APP_KEY',
            'APP_MAINTENANCE_DRIVER',
            'BCRYPT_ROUNDS',
            'CACHE_STORE',
            'DB_CONNECTION',
            'DB_DATABASE',
            'DB_HOST',
            'DB_PORT',
            'DB_USERNAME',
            'DB_PASSWORD',
            'MAIL_MAILER',
            'PULSE_ENABLED',
            'QUEUE_CONNECTION',
            'SESSION_DRIVER',
            'TELESCOPE_ENABLED',
        ] as $name) {
            unset($_SERVER[$name]);
        }

        return parent::createApplication();
    }
}
