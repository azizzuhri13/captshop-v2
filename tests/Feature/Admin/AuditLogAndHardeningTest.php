<?php

namespace Tests\Feature\Admin;

use App\Models\Admin;
use App\Models\AuditLog;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductItem;
use App\Models\Transaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class AuditLogAndHardeningTest extends TestCase
{
    use RefreshDatabase;

    protected function superAdmin(): Admin
    {
        return Admin::create([
            'name' => 'Super Admin',
            'email' => 'super@test.com',
            'password' => 'super-password',
            'role' => 'super_admin',
            'is_active' => true,
        ]);
    }

    protected function createTransaction(): Transaction
    {
        $category = Category::create(['name' => 'Games', 'slug' => 'games']);
        $product = Product::create(['category_id' => $category->id, 'name' => 'ML', 'slug' => 'ml']);
        $item = ProductItem::create([
            'product_id' => $product->id,
            'name' => '86 Diamonds',
            'buy_price' => '1000',
            'sell_price' => '1500',
        ]);

        return Transaction::create([
            'invoice_id' => 'INV-AUDIT-000001',
            'product_item_id' => $item->id,
            'customer_data' => ['user_id' => 'UIDGAMER999XYZ'],
            'customer_phone' => '081234567890',
            'amount' => '15000',
            'total' => '15000',
            'status' => 'paid',
        ]);
    }

    public function test_failed_login_is_logged(): void
    {
        Log::spy();

        $this->post(route('admin.login'), [
            'email' => 'hacker@evil.com',
            'password' => 'wrong-password',
        ]);

        Log::shouldHaveReceived('warning')
            ->once()
            ->withArgs(fn ($message, $context) => str_contains($message, 'GAGAL'));

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'login_failed',
            'admin_id' => null,
        ]);
    }

    public function test_successful_login_is_audited(): void
    {
        $admin = Admin::create([
            'name' => 'Admin',
            'email' => 'admin@test.com',
            'password' => 'password-123',
            'role' => 'admin',
            'is_active' => true,
        ]);

        $this->post(route('admin.login'), [
            'email' => 'admin@test.com',
            'password' => 'password-123',
        ]);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'login_success',
            'admin_id' => $admin->id,
        ]);
    }

    public function test_mark_completed_writes_audit_log(): void
    {
        $admin = $this->superAdmin();
        $this->actingAs($admin, 'admin');

        $transaction = $this->createTransaction();

        $this->post(route('admin.transactions.mark-completed', $transaction))->assertRedirect();

        $log = AuditLog::query()->where('action', 'mark_completed')->first();

        $this->assertNotNull($log);
        $this->assertSame($admin->id, $log->admin_id);
        $this->assertSame(Transaction::class, $log->model);
        $this->assertSame($transaction->id, $log->model_id);
        $this->assertSame(['status' => 'paid'], $log->old_values);
        $this->assertSame(['status' => 'completed'], $log->new_values);
    }

    public function test_settings_update_audited_without_secret_values(): void
    {
        $this->actingAs($this->superAdmin(), 'admin');

        $this->put(route('admin.settings.update'), [
            'settings' => [
                'site_name' => 'CaptShop Baru',
                'fonnte_token' => 'RAHASIA-BARU-XYZ',
            ],
        ]);

        $log = AuditLog::query()->where('action', 'update')->where('model', 'SiteSetting')->first();

        $this->assertNotNull($log);
        $this->assertSame('CaptShop Baru', $log->new_values['site_name']);
        $this->assertSame('[tersembunyi]', $log->new_values['fonnte_token']);
        $this->assertStringNotContainsString('RAHASIA-BARU-XYZ', json_encode($log->new_values));
    }

    public function test_security_headers_present_on_web_responses(): void
    {
        $response = $this->get('/');

        $response->assertOk();
        $this->assertSame('DENY', $response->headers->get('X-Frame-Options'));
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        $this->assertSame('strict-origin-when-cross-origin', $response->headers->get('Referrer-Policy'));
        $this->assertStringContainsString("default-src 'self'", $response->headers->get('Content-Security-Policy'));
        $this->assertStringContainsString("frame-ancestors 'none'", $response->headers->get('Content-Security-Policy'));
        // HSTS tidak boleh dikirim lewat HTTP biasa (hanya HTTPS).
        $this->assertNull($response->headers->get('Strict-Transport-Security'));
    }

    public function test_security_headers_present_on_api_responses(): void
    {
        $response = $this->postJson('/coupon/check', ['code' => 'X', 'amount' => 1000]);

        // 422 = kupon tidak ditemukan, tapi header tetap harus terpasang.
        $response->assertStatus(422);
        $this->assertSame('DENY', $response->headers->get('X-Frame-Options'));
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
    }

    public function test_track_search_does_not_expose_customer_data(): void
    {
        $transaction = $this->createTransaction();

        $response = $this->post('/cek-transaksi', ['q' => '081234567890']);

        $response->assertOk();
        $response->assertSee($transaction->invoice_id);

        // Data sensitif (User ID game) tidak boleh muncul di hasil pencarian.
        $response->assertDontSee('UIDGAMER999XYZ');
        $response->assertDontSee('customer_data');
    }

    public function test_track_search_by_invoice_shows_only_minimal_summary(): void
    {
        $transaction = $this->createTransaction();

        $response = $this->post('/cek-transaksi', ['q' => $transaction->invoice_id]);

        $response->assertOk();
        $response->assertSee($transaction->invoice_id);
        $response->assertDontSee('UIDGAMER999XYZ');
    }

    public function test_super_admin_can_create_and_edit_admin_account(): void
    {
        $this->actingAs($this->superAdmin(), 'admin');

        $this->post(route('admin.accounts.store'), [
            'name' => 'CS Baru',
            'email' => 'csbaru@test.com',
            'password' => 'password-123',
            'role' => 'cs',
            'is_active' => '1',
        ])->assertRedirect(route('admin.accounts.index'));

        $admin = Admin::query()->where('email', 'csbaru@test.com')->first();
        $this->assertNotNull($admin);
        $this->assertSame('cs', $admin->role);

        // Edit: ganti role, password kosong = password lama tetap berlaku.
        $this->put(route('admin.accounts.update', $admin), [
            'name' => 'CS Baru',
            'email' => 'csbaru@test.com',
            'password' => '',
            'role' => 'operator',
            'is_active' => '1',
        ])->assertRedirect(route('admin.accounts.index'));

        $this->assertSame('operator', $admin->fresh()->role);

        // Password lama masih bisa dipakai login.
        $this->post(route('admin.logout'));
        $this->assertGuest('admin');
        $this->post(route('admin.login'), [
            'email' => 'csbaru@test.com',
            'password' => 'password-123',
        ]);
        $this->assertAuthenticated('admin');

        // Audit trail akun.
        $this->assertDatabaseHas('audit_logs', ['action' => 'create', 'model' => Admin::class]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'update', 'model' => Admin::class]);
    }

    public function test_admin_cannot_delete_self_or_last_super_admin(): void
    {
        $super = $this->superAdmin();
        $this->actingAs($super, 'admin');

        // Hapus diri sendiri ditolak.
        $this->delete(route('admin.accounts.destroy', $super))
            ->assertRedirect()
            ->assertSessionHas('error');
        $this->assertNotNull($super->fresh());

        // Hapus super admin terakhir (via akun lain) juga ditolak.
        $other = Admin::create([
            'name' => 'Other Super',
            'email' => 'other@test.com',
            'password' => 'password-123',
            'role' => 'super_admin',
            'is_active' => true,
        ]);

        // Dua super admin: hapus salah satu boleh.
        $this->delete(route('admin.accounts.destroy', $other))->assertRedirect();
        $this->assertNull($other->fresh());

        // Super terakhir: hapus ditolak.
        $this->delete(route('admin.accounts.destroy', $super))
            ->assertSessionHas('error');
        $this->assertNotNull($super->fresh());
    }

    public function test_non_super_admin_cannot_manage_accounts(): void
    {
        $cs = Admin::create([
            'name' => 'CS',
            'email' => 'cs@test.com',
            'password' => 'password-123',
            'role' => 'cs',
            'is_active' => true,
        ]);

        $this->actingAs($cs, 'admin');

        $this->get(route('admin.accounts.index'))->assertForbidden();
        $this->post(route('admin.accounts.store'), [
            'name' => 'Hacker',
            'email' => 'hacker@test.com',
            'password' => 'password-123',
            'role' => 'super_admin',
            'is_active' => '1',
        ])->assertForbidden();

        $this->assertSame(1, Admin::count()); // hanya akun CS, tidak ada akun baru.
    }
}
