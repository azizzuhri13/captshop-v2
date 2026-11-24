<?php

namespace Tests\Feature\Admin;

use App\Models\Admin;
use App\Models\SiteSetting;
use App\Services\Notification\WhatsAppGatewayStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WhatsAppGatewayStatusTest extends TestCase
{
    use RefreshDatabase;

    protected function superAdmin(): Admin
    {
        return Admin::create([
            'name' => 'Super Admin',
            'email' => 'gateway-super@test.com',
            'password' => 'super-password',
            'role' => 'super_admin',
            'is_active' => true,
        ]);
    }

    protected function csAdmin(): Admin
    {
        return Admin::create([
            'name' => 'CS Admin',
            'email' => 'gateway-cs@test.com',
            'password' => 'cs-password',
            'role' => 'cs',
            'is_active' => true,
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();
        Config::set('services.whatsapp_gateway.url', 'http://127.0.0.1:3211');
    }

    public function test_settings_panel_renders_connected_gateway_without_secret_or_raw_response(): void
    {
        Config::set('services.whatsapp_gateway.key', 'internal-test-secret');
        Http::fake([
            '127.0.0.1:3211/v1/session/status' => Http::response([
                'state' => 'connected',
                'sender' => ['jid' => '628999888777@s.whatsapp.net', 'display' => '628999888777'],
                'last_connected_at' => '2026-11-24T03:00:00+00:00',
                'last_disconnected_at' => '2026-11-23T03:00:00+00:00',
                'queue' => ['queued' => 4, 'retryable' => 2, 'failed_terminal' => 1],
            ], 200),
        ]);
        $this->actingAs($this->superAdmin(), 'admin');

        $response = $this->get(route('admin.settings.edit'));

        $response->assertOk()->assertSee('Terhubung')->assertSee('62899••••777')->assertSee('4 queued')->assertSee('2 retryable')->assertSee('1 gagal');
        $response->assertSee('2026-11-24T03:00:00+00:00')->assertSee('2026-11-23T03:00:00+00:00');
        $response->assertDontSee('internal-test-secret')->assertDontSee('session_state')->assertDontSee('reason')->assertDontSee('628999888777');
        Http::assertSent(fn ($request) => $request->url() === 'http://127.0.0.1:3211/v1/session/status'
            && $request->header('X-Internal-Key')[0] === 'internal-test-secret');
    }

    public function test_settings_panel_fails_closed_when_gateway_is_not_ready(): void
    {
        Config::set('services.whatsapp_gateway.key', 'internal-test-secret');
        Http::fake([
            '127.0.0.1:3211/v1/session/status' => Http::response([
                'state' => 'unpaired',
                'sender' => null,
                'last_connected_at' => null,
                'last_disconnected_at' => '2026-11-24T03:00:00+00:00',
                'queue' => ['queued' => 0, 'retryable' => 0, 'failed_terminal' => 0],
            ], 200),
        ]);
        $this->actingAs($this->superAdmin(), 'admin');

        $response = $this->get(route('admin.settings.edit'));

        $response->assertOk()->assertSee('Terputus')->assertSee('Gateway tidak terhubung.');
        $response->assertDontSee('SESSION_UNPAIRED');
    }

    public function test_malformed_payload_is_unavailable_and_not_rendered(): void
    {
        Config::set('services.whatsapp_gateway.key', 'internal-test-secret');
        Http::fake(['127.0.0.1:3211/v1/session/status' => Http::response([
            'state' => 'connected',
            'sender' => ['display' => 'UNTRUSTED-628999888777'],
            'queue' => ['queued' => 'not-a-number'],
            'raw_error' => 'do-not-render',
        ], 200)]);
        $this->actingAs($this->superAdmin(), 'admin');

        $this->get(route('admin.settings.edit'))
            ->assertOk()
            ->assertSee('Tidak tersedia')
            ->assertDontSee('do-not-render')
            ->assertDontSee('628999888777');
    }

    public function test_settings_panel_fails_closed_for_non_loopback_gateway_without_request_or_secret(): void
    {
        Config::set('services.whatsapp_gateway.url', 'https://evil.example.com');
        Config::set('services.whatsapp_gateway.key', 'internal-test-secret');
        Http::fake();
        $this->actingAs($this->superAdmin(), 'admin');

        $this->get(route('admin.settings.edit'))
            ->assertOk()
            ->assertSee('Tidak tersedia')
            ->assertDontSee('internal-test-secret')
            ->assertDontSee('evil.example.com');

        Http::assertNothingSent();
    }

    public function test_non_superadmin_cannot_view_gateway_status_panel(): void
    {
        $this->actingAs($this->csAdmin(), 'admin');

        $this->get(route('admin.settings.edit'))->assertForbidden();
    }

    public function test_legacy_gateway_settings_are_deleted_by_cleanup_migration(): void
    {
        $legacyTokenKey = 'fon'.'nte_token';
        $legacyUrlKey = 'fon'.'nte_base_url';
        SiteSetting::set($legacyTokenKey, 'legacy-secret');
        SiteSetting::set($legacyUrlKey, 'https://legacy.invalid');
        SiteSetting::set('site_name', 'Keep this setting');

        $migration = require base_path('database/migrations/2026_11_24_000001_remove_legacy_whatsapp_site_settings.php');
        $migration->up();

        $this->assertDatabaseMissing('site_settings', ['key' => $legacyTokenKey]);
        $this->assertDatabaseMissing('site_settings', ['key' => $legacyUrlKey]);
        $this->assertDatabaseHas('site_settings', ['key' => 'site_name', 'value' => 'Keep this setting']);
    }
}
