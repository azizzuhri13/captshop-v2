<?php

namespace Tests\Feature\Admin;

use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminAuthTest extends TestCase
{
    use RefreshDatabase;

    protected function admin(): Admin
    {
        return Admin::create([
            'name' => 'Test Admin',
            'email' => 'admin@test.com',
            'password' => 'secret-password',
            'role' => 'super_admin',
            'is_active' => true,
        ]);
    }

    public function test_login_form_renders(): void
    {
        $this->get('/admin/login')->assertStatus(200)->assertSee('Panel Admin');
    }

    public function test_admin_cannot_access_dashboard_without_login(): void
    {
        $response = $this->get('/admin/dashboard');

        $response->assertRedirect(route('admin.login'));
    }

    public function test_admin_can_login_with_correct_credentials(): void
    {
        $admin = $this->admin();

        $response = $this->post('/admin/login', [
            'email' => $admin->email,
            'password' => 'secret-password',
        ]);

        $response->assertRedirect(route('admin.dashboard'));
        $this->assertAuthenticatedAs($admin, 'admin');
    }

    public function test_admin_cannot_login_with_wrong_password(): void
    {
        $admin = $this->admin();

        $response = $this->post('/admin/login', [
            'email' => $admin->email,
            'password' => 'wrong-password',
        ]);

        $response->assertSessionHasErrors('email');
        $this->assertGuest('admin');
    }

    public function test_inactive_admin_cannot_login(): void
    {
        $admin = $this->admin();
        $admin->update(['is_active' => false]);

        $response = $this->post('/admin/login', [
            'email' => $admin->email,
            'password' => 'secret-password',
        ]);

        $response->assertSessionHasErrors('email');
        $this->assertGuest('admin');
    }

    public function test_admin_can_logout(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin, 'admin');

        $response = $this->post('/admin/logout');

        $response->assertRedirect(route('admin.login'));
        $this->assertGuest('admin');
    }
}
