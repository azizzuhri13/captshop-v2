<?php

namespace Database\Seeders;

use App\Models\SiteSetting;
use Illuminate\Database\Seeder;

class SiteSettingSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $settings = [
            'site_name' => 'CaptShop',
            'site_description' => 'Platform top-up game, voucher digital, dan pembayaran PPOB tercepat dan termurah.',
            'site_logo' => null,
            'site_favicon' => null,
            'contact_whatsapp' => '6281234567890',
            'contact_email' => 'support@captshop.test',
            'contact_instagram' => null,
            'contact_facebook' => null,
            'maintenance_mode' => 'false',
        ];

        foreach ($settings as $key => $value) {
            SiteSetting::updateOrCreate(
                ['key' => $key],
                ['value' => $value]
            );
        }
    }
}
