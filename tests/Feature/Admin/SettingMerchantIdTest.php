<?php

namespace Tests\Feature\Admin;

use App\Models\Admin;
use App\Models\SiteSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Merchant ID Belibayar hanya metadata (keaslian dijamin signature), tetapi
 * salah ketik di sini pernah menyesatkan operasional. Form harus menolak
 * nilai yang tidak berformat BYR + 6 karakter.
 */
class SettingMerchantIdTest extends TestCase
{
    use RefreshDatabase;

    private function loginAdmin(): Admin
    {
        $admin = Admin::create([
            'name' => 'Test Admin',
            'email' => 'admin@test.com',
            'password' => 'secret-password',
            'role' => 'super_admin',
            'is_active' => true,
        ]);

        $this->actingAs($admin, 'admin');

        return $admin;
    }

    public function test_rejects_malformed_merchant_id(): void
    {
        $this->loginAdmin();

        $this->put('/admin/settings', [
            'settings' => ['belibayar_merchant_id' => 'BYRS774587'],
        ])->assertSessionHasErrors('settings.belibayar_merchant_id');

        $this->assertNull(SiteSetting::get('belibayar_merchant_id'));
    }

    public function test_accepts_documented_merchant_id_format(): void
    {
        $this->loginAdmin();

        $this->put('/admin/settings', [
            'settings' => ['belibayar_merchant_id' => 'BYR670455'],
        ])->assertSessionHasNoErrors();

        $this->assertSame('BYR670455', SiteSetting::get('belibayar_merchant_id'));
    }

    public function test_legacy_invalid_value_does_not_block_unrelated_settings(): void
    {
        SiteSetting::updateOrCreate(['key' => 'belibayar_merchant_id'], ['value' => 'BYRS774587']);
        $this->loginAdmin();

        $this->put('/admin/settings', [
            'settings' => [
                'site_name' => 'CaptShop Test',
                'belibayar_merchant_id' => 'BYRS774587',
            ],
        ])->assertSessionHasNoErrors();

        $this->assertSame('CaptShop Test', SiteSetting::get('site_name'));
    }
}
