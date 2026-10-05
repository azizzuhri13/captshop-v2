<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\SiteSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LayoutBrandingTest extends TestCase
{
    use RefreshDatabase;

    protected function loginAdmin(): Admin
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

    public function test_public_layout_renders_logo_and_favicon_from_settings(): void
    {
        SiteSetting::set('site_logo', '/storage/settings/logo.png');
        SiteSetting::set('site_favicon', '/storage/settings/favicon.ico');

        $response = $this->get('/');

        $response->assertStatus(200);
        $response->assertSee('<link rel="icon" href="/storage/settings/favicon.ico">', false);
        $response->assertSee('<img src="/storage/settings/logo.png"', false);
        $response->assertDontSee('text-white">C</span>', false);
    }

    public function test_public_layout_falls_back_to_default_branding_when_settings_empty(): void
    {
        $response = $this->get('/');

        $response->assertStatus(200);
        $response->assertDontSee('<link rel="icon"', false);
        $response->assertDontSee('<img src="/storage/settings/', false);
        $response->assertSee('text-white">C</span>', false);
    }

    public function test_admin_layout_renders_logo_and_favicon_from_settings(): void
    {
        $this->loginAdmin();

        SiteSetting::set('site_logo', '/storage/settings/logo.png');
        SiteSetting::set('site_favicon', '/storage/settings/favicon.ico');

        $response = $this->get('/admin/dashboard');

        $response->assertStatus(200);
        $response->assertSee('<link rel="icon" href="/storage/settings/favicon.ico">', false);
        $response->assertSee('<img src="/storage/settings/logo.png"', false);
        $response->assertDontSee('text-white">C</span>', false);
    }

    public function test_admin_layout_falls_back_to_default_branding_when_settings_empty(): void
    {
        $this->loginAdmin();

        $response = $this->get('/admin/dashboard');

        $response->assertStatus(200);
        $response->assertDontSee('<link rel="icon"', false);
        $response->assertDontSee('<img src="/storage/settings/', false);
        $response->assertSee('text-white">C</span>', false);
    }
}
