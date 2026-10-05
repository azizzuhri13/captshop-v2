<?php

namespace App\Support;

use App\Models\SiteSetting;

class Credentials
{
    /**
     * Ambil nilai kredensial/setting dari site_settings (diatur via admin
     * panel), jatuh kembali ke nilai config/.env bila belum diatur.
     *
     * Gagal kueri (mis. tabel belum ada) diperlakukan sebagai belum diatur
     * agar service tetap memakai nilai config.
     */
    public static function get(string $key, ?string $default = null): ?string
    {
        try {
            $value = SiteSetting::get($key);
        } catch (\Throwable) {
            $value = null;
        }

        return ($value !== null && $value !== '') ? $value : $default;
    }
}
