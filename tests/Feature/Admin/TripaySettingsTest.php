<?php

namespace Tests\Feature\Admin;

use App\Models\Admin;
use App\Models\PaymentChannel;
use App\Models\SiteSetting;
use App\Support\Credentials;
use Database\Seeders\PaymentChannelSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Panel Pengaturan harus cukup untuk memindahkan gateway ke Tripay tanpa
 * menyentuh .env:
 *  - mode sandbox/live dipilih dari dropdown dan nilai asing ditolak;
 *  - API key / private key tidak pernah dirender balik ke browser, hanya
 *    penanda "Terisi"; kolom kosong mempertahankan nilai, "Hapus" membuang
 *    nilai tersimpan (tidak ada fallback config/.env untuk kredensial Tripay);
 *  - callback URL read-only dan host-nya captshop.id (bukan next.captshop.id);
 *  - ringkasan kesiapan memberi tahu admin apa yang masih kurang.
 *
 * Nama key mengikuti yang sudah dibaca TripayClient lewat Credentials:
 * tripay_api_key, tripay_private_key, tripay_merchant_code, tripay_mode.
 */
class TripaySettingsTest extends TestCase
{
    use RefreshDatabase;

    private const API_KEY = 'TRIPAY-API-KEY-9F3K';

    private const PRIVATE_KEY = 'TRIPAY-PRIVATE-KEY-7Q2Z';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    private function superAdmin(): Admin
    {
        return Admin::create([
            'name' => 'Super Admin',
            'email' => 'super@test.com',
            'password' => 'super-password',
            'role' => 'super_admin',
            'is_active' => true,
        ]);
    }

    private function actAsSuperAdmin(): void
    {
        $this->actingAs($this->superAdmin(), 'admin');
    }

    public function test_settings_page_renders_tripay_section_with_mode_choices(): void
    {
        $this->actAsSuperAdmin();

        $response = $this->get(route('admin.settings.edit'));

        $response->assertOk()
            ->assertSee('Payment Gateway (Tripay)')
            ->assertSee('name="settings[tripay_mode]"', false)
            ->assertSee('value="sandbox"', false)
            ->assertSee('value="live"', false)
            ->assertSee('name="settings[tripay_api_key]"', false)
            ->assertSee('name="settings[tripay_private_key]"', false)
            ->assertSee('name="settings[tripay_merchant_code]"', false);
    }

    public function test_stored_secrets_are_marked_without_rendering_any_part_of_them(): void
    {
        SiteSetting::set('tripay_api_key', self::API_KEY);
        SiteSetting::set('tripay_private_key', self::PRIVATE_KEY);
        $this->actAsSuperAdmin();

        $html = $this->get(route('admin.settings.edit'))->assertOk()->getContent();

        $this->assertStringContainsString('Terisi', $html);
        $this->assertStringNotContainsString(self::API_KEY, $html);
        $this->assertStringNotContainsString(self::PRIVATE_KEY, $html);
        // Akhiran/panjang nilai lama juga tidak boleh bocor: itu menyempitkan tebakan.
        $this->assertStringNotContainsString('9F3K', $html);
        $this->assertStringNotContainsString('7Q2Z', $html);
        $this->assertStringNotContainsString('value="'.self::API_KEY, $html);
        $this->assertStringNotContainsString('value="'.self::PRIVATE_KEY, $html);
    }

    public function test_blank_tripay_secret_inputs_preserve_stored_values(): void
    {
        SiteSetting::set('tripay_api_key', self::API_KEY);
        SiteSetting::set('tripay_private_key', self::PRIVATE_KEY);
        $this->actAsSuperAdmin();

        $this->put(route('admin.settings.update'), [
            'settings' => [
                'site_name' => 'CaptShop Tripay',
                'tripay_api_key' => '',
                'tripay_private_key' => '',
            ],
        ])->assertSessionHasNoErrors();

        $this->assertSame(self::API_KEY, SiteSetting::get('tripay_api_key'));
        $this->assertSame(self::PRIVATE_KEY, SiteSetting::get('tripay_private_key'));
        $this->assertSame('CaptShop Tripay', SiteSetting::get('site_name'));
    }

    public function test_clear_checkbox_removes_override_so_config_fallback_applies(): void
    {
        SiteSetting::set('tripay_api_key', self::API_KEY);
        config()->set('payment.tripay.api_key', 'ENV-FALLBACK-KEY');
        $this->actAsSuperAdmin();

        $this->put(route('admin.settings.update'), [
            'settings' => [],
            'clear_settings' => ['tripay_api_key' => 1],
        ])->assertSessionHasNoErrors();

        $this->assertNull(SiteSetting::get('tripay_api_key'));
        $this->assertSame(
            'ENV-FALLBACK-KEY',
            Credentials::get('tripay_api_key', config('payment.tripay.api_key'))
        );
    }

    public function test_invalid_tripay_mode_is_rejected_and_not_persisted(): void
    {
        $this->actAsSuperAdmin();

        $this->put(route('admin.settings.update'), [
            'settings' => ['tripay_mode' => 'production'],
        ])->assertSessionHasErrors('settings.tripay_mode');

        $this->assertNull(SiteSetting::get('tripay_mode'));
    }

    public function test_valid_tripay_modes_are_persisted(): void
    {
        $this->actAsSuperAdmin();

        $this->put(route('admin.settings.update'), [
            'settings' => ['tripay_mode' => 'live'],
        ])->assertSessionHasNoErrors();

        $this->assertSame('live', SiteSetting::get('tripay_mode'));
    }

    public function test_callback_url_is_read_only_and_hardcoded_to_captshop_id(): void
    {
        $this->actAsSuperAdmin();

        $html = $this->get(route('admin.settings.edit'))->assertOk()->getContent();

        $this->assertStringContainsString('value="https://captshop.id/webhook/tripay"', $html);
        $this->assertStringNotContainsString('value="https://next.captshop.id', $html);
        // Tidak boleh ada name= sehingga nilainya tidak pernah ikut tersimpan
        // dari form; callback hanya berasal dari config.
        $this->assertStringNotContainsString('name="settings[tripay_callback_url]"', $html);
        $this->assertStringContainsString('readonly', $html);
    }

    public function test_readiness_summary_reports_missing_credentials_then_ready(): void
    {
        $this->seed(PaymentChannelSeeder::class);
        $this->actAsSuperAdmin();

        $this->get(route('admin.settings.edit'))
            ->assertOk()
            ->assertSee('Belum lengkap')
            ->assertSee('API Key terisi')
            ->assertSee('Callback host captshop.id');

        SiteSetting::set('tripay_api_key', self::API_KEY);
        SiteSetting::set('tripay_private_key', self::PRIVATE_KEY);
        SiteSetting::set('tripay_merchant_code', 'T0001');
        SiteSetting::set('tripay_mode', 'sandbox');
        PaymentChannel::query()->where('gateway', 'tripay')->where('code', 'BCA')->update(['is_active' => true]);

        $this->get(route('admin.settings.edit'))
            ->assertOk()
            ->assertSee('Siap diaktifkan');
    }

    /**
     * Kontrol perpindahan gateway hanya boleh muncul bila backend sudah
     * mendaftarkan setting-nya; panel tidak boleh menawarkan tombol yang
     * tidak mengubah apa pun.
     */
    public function test_gateway_switch_control_matches_backend_schema(): void
    {
        $this->actAsSuperAdmin();

        $response = $this->get(route('admin.settings.edit'))->assertOk();
        $schema = (array) $response->viewData('schema');

        $key = array_key_exists('payment_gateway', $schema)
            ? 'payment_gateway'
            : (array_key_exists('payment_default', $schema) ? 'payment_default' : null);

        if ($key === null) {
            $response->assertDontSee('name="settings[payment_gateway]"', false)
                ->assertDontSee('name="settings[payment_default]"', false);

            return;
        }

        $response->assertSee('name="settings['.$key.']"', false)
            ->assertSee('value="belibayar"', false)
            ->assertSee('value="tripay"', false);
    }

    /**
     * Regresi: field Tripay pernah dirender dua kali (satu di loop generik,
     * satu di section khusus) sehingga browser mengirim dua nilai dengan name
     * sama dan nilai terakhir menang. Saklar gateway juga pernah muncul dua
     * kali — satu select di dalam form utama (tanpa guard) dan satu form
     * terpisah yang teraudit. Kontrak sekarang: tepat satu input per key dan
     * tepat satu kontrol saklar (`name="gateway"`).
     */
    public function test_each_tripay_field_and_the_gateway_switch_render_exactly_once(): void
    {
        $this->actAsSuperAdmin();

        $html = $this->get(route('admin.settings.edit'))->assertOk()->getContent();

        foreach ([
            'settings[tripay_mode]',
            'settings[tripay_api_key]',
            'settings[tripay_private_key]',
            'settings[tripay_merchant_code]',
        ] as $name) {
            $this->assertSame(
                1,
                substr_count($html, 'name="'.$name.'"'),
                "Field {$name} harus dirender tepat satu kali."
            );
        }

        foreach (['tripay_mode', 'tripay_api_key', 'tripay_private_key', 'tripay_merchant_code'] as $id) {
            $this->assertSame(1, substr_count($html, 'id="'.$id.'"'), "Elemen #{$id} harus unik.");
        }

        // Satu saklar: form terpisah yang memeriksa readiness.
        $this->assertSame(1, substr_count($html, 'name="gateway"'), 'Saklar gateway harus tepat satu.');
        $this->assertSame(0, substr_count($html, 'name="settings[payment_gateway]"'), 'Saklar tidak boleh ada di form umum.');
        $this->assertSame(0, substr_count($html, 'name="settings[payment_default]"'));
    }

    /**
     * Jalur tulis kedua ditutup: POST ke form pengaturan umum tidak boleh
     * memindahkan gateway, karena itu melewati pemeriksaan readiness dan
     * audit log milik updateGateway.
     */
    public function test_gateway_cannot_be_switched_through_the_general_settings_form(): void
    {
        $this->actAsSuperAdmin();

        $this->put(route('admin.settings.update'), [
            'settings' => ['payment_gateway' => 'tripay'],
        ])->assertSessionHasErrors('settings.payment_gateway');

        $this->assertNull(SiteSetting::get('payment_gateway'));
        $this->assertSame('belibayar', \App\Support\PaymentGateway::effective());
    }
}