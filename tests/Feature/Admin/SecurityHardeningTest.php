<?php

namespace Tests\Feature\Admin;

use App\Models\Admin;
use App\Models\Banner;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductItem;
use App\Models\SiteSetting;
use App\Models\Transaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class SecurityHardeningTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Binary PNG 1x1 piksel yang valid — dipakai karena ekstensi GD
     * tidak tersedia di lingkungan ini (UploadedFile::fake()->image()
     * membutuhkan GD).
     */
    protected const PNG_1X1 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

    protected function fakeImage(string $name): UploadedFile
    {
        $path = sys_get_temp_dir().'/'.uniqid('img_').'.png';
        file_put_contents($path, base64_decode(self::PNG_1X1));

        return new UploadedFile($path, $name, 'image/png', test: true);
    }

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

    protected function csAdmin(): Admin
    {
        return Admin::create([
            'name' => 'CS Admin',
            'email' => 'cs@test.com',
            'password' => 'cs-password',
            'role' => 'cs',
            'is_active' => true,
        ]);
    }

    public function test_settings_page_does_not_leak_stored_secrets(): void
    {
        SiteSetting::set('belibayar_api_key', 'SECRET-BELIBAYAR-KEY-123');

        $this->actingAs($this->superAdmin(), 'admin');

        $response = $this->get(route('admin.settings.edit'));

        $response->assertOk();
        $this->assertStringNotContainsString('SECRET-BELIBAYAR-KEY-123', $response->getContent());
        $this->assertStringNotContainsString('value="SECRET', $response->getContent());
    }

    public function test_settings_secret_not_overwritten_when_left_blank(): void
    {
        SiteSetting::set('fonnte_token', 'FONNTE-OLD-TOKEN');

        $this->actingAs($this->superAdmin(), 'admin');

        $this->put(route('admin.settings.update'), [
            'settings' => [
                'site_name' => 'CaptShop Baru',
                'fonnte_token' => '',
            ],
        ]);

        $this->assertSame('FONNTE-OLD-TOKEN', SiteSetting::get('fonnte_token'));
        $this->assertSame('CaptShop Baru', SiteSetting::get('site_name'));
    }

    public function test_settings_secret_not_overwritten_when_filled_with_bullet_mask_characters(): void
    {
        SiteSetting::set('belibayar_secret_key', 'REAL-SECRET-12345');

        $this->actingAs($this->superAdmin(), 'admin');

        $this->put(route('admin.settings.update'), [
            'settings' => [
                'belibayar_secret_key' => '••••••••••••••••••••••••••••••',
            ],
        ]);

        $this->assertSame('REAL-SECRET-12345', SiteSetting::get('belibayar_secret_key'));
    }

    public function test_settings_secret_can_be_cleared(): void
    {
        SiteSetting::set('fonnte_token', 'FONNTE-OLD-TOKEN');

        $this->actingAs($this->superAdmin(), 'admin');

        $this->put(route('admin.settings.update'), [
            'settings' => [],
            'clear_settings' => ['fonnte_token' => '1'],
        ]);

        $this->assertNull(SiteSetting::get('fonnte_token'));
    }

    public function test_banner_rejects_javascript_url(): void
    {
        Storage::fake('public');
        $this->actingAs($this->superAdmin(), 'admin');

        $response = $this->post(route('admin.banners.store'), [
            'title' => 'Banner Jahat',
            'url' => 'javascript:alert(document.cookie)',
            'type' => 'carousel',
            'is_active' => '1',
            'image' => $this->fakeImage('banner.png'),
        ]);

        $response->assertSessionHasErrors('url');
        $this->assertSame(0, Banner::count());
    }

    public function test_banner_accepts_https_url(): void
    {
        Storage::fake('public');
        $this->actingAs($this->superAdmin(), 'admin');

        $this->post(route('admin.banners.store'), [
            'title' => 'Banner Aman',
            'url' => 'https://example.com/promo',
            'type' => 'carousel',
            'is_active' => '1',
            'image' => $this->fakeImage('banner.png'),
        ])->assertRedirect(route('admin.banners.index'));

        $this->assertSame('https://example.com/promo', Banner::query()->first()->url);
    }

    public function test_cs_role_cannot_access_settings(): void
    {
        $this->actingAs($this->csAdmin(), 'admin');

        $this->get(route('admin.settings.edit'))->assertForbidden();
        $this->put(route('admin.settings.update'), [])->assertForbidden();
    }

    public function test_cs_role_cannot_manage_products_or_export_transactions(): void
    {
        $this->actingAs($this->csAdmin(), 'admin');

        $this->get(route('admin.products.index'))->assertForbidden();
        $this->get(route('admin.categories.index'))->assertForbidden();
        $this->get(route('admin.banners.index'))->assertForbidden();
        $this->get(route('admin.transactions.export'))->assertForbidden();
    }

    public function test_cs_role_can_view_transactions_but_not_mark_completed(): void
    {
        $this->actingAs($this->csAdmin(), 'admin');

        $this->get(route('admin.transactions.index'))->assertOk();

        $category = Category::create(['name' => 'Games', 'slug' => 'games']);
        $product = Product::create(['category_id' => $category->id, 'name' => 'ML', 'slug' => 'ml']);
        $item = ProductItem::create([
            'product_id' => $product->id,
            'name' => '86 Diamonds',
            'buy_price' => '1000',
            'sell_price' => '1500',
        ]);

        $transaction = Transaction::create([
            'invoice_id' => 'INV-SEC-TEST-01',
            'product_item_id' => $item->id,
            'customer_data' => ['user_id' => '12345'],
            'customer_phone' => '081234567890',
            'amount' => '1000',
            'total' => '1000',
            'status' => 'pending',
        ]);

        $this->post(route('admin.transactions.mark-completed', $transaction))->assertForbidden();

        $this->assertSame('pending', $transaction->fresh()->status);
    }

    public function test_super_admin_can_access_everything(): void
    {
        $this->actingAs($this->superAdmin(), 'admin');

        $this->get(route('admin.settings.edit'))->assertOk();
        $this->get(route('admin.products.index'))->assertOk();
        $this->get(route('admin.transactions.index'))->assertOk();
    }

    public function test_maintenance_mode_blocks_frontend_but_not_admin(): void
    {
        SiteSetting::set('maintenance_mode', 'true');

        // Frontend & API terblokir.
        $this->get('/')->assertStatus(503);
        $this->postJson('/coupon/check', ['code' => 'X', 'amount' => 1000])->assertStatus(503);

        // Admin panel & webhook tetap bisa diakses (403 = signature invalid,
        // artinya request lolos middleware maintenance dan sampai controller).
        $this->get(route('admin.login'))->assertOk();
        $this->postJson('/webhook/belibayar', [])->assertStatus(403);

        SiteSetting::set('maintenance_mode', 'false');

        $this->get('/')->assertOk();
    }

    public function test_maintenance_mode_disabled_by_default(): void
    {
        $this->get('/')->assertOk();
    }

    public function test_settings_rejects_svg_upload_for_logo(): void
    {
        Storage::fake('public');
        $this->actingAs($this->superAdmin(), 'admin');

        $svgFile = UploadedFile::fake()->create('malicious.svg', 10, 'image/svg+xml');

        $response = $this->put(route('admin.settings.update'), [
            'site_logo' => $svgFile,
        ]);

        $response->assertSessionHasErrors('site_logo');
    }

    public function test_cannot_demote_or_deactivate_last_active_super_admin(): void
    {
        $admin = $this->superAdmin();
        $this->actingAs($admin, 'admin');

        $response = $this->put(route('admin.accounts.update', $admin), [
            'name' => 'Super Admin',
            'email' => $admin->email,
            'role' => 'operator',
            'is_active' => '1',
        ]);

        $response->assertSessionHas('error');
        $this->assertSame('super_admin', $admin->fresh()->role);

        $responseDeactivate = $this->put(route('admin.accounts.update', $admin), [
            'name' => 'Super Admin',
            'email' => $admin->email,
            'role' => 'super_admin',
            'is_active' => '0',
        ]);

        $responseDeactivate->assertSessionHas('error');
        $this->assertTrue($admin->fresh()->is_active);
    }
}
