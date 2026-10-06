<?php

namespace Tests\Feature\Admin;

use App\Models\Admin;
use App\Models\Banner;
use App\Models\Category;
use App\Models\Coupon;
use App\Models\PaymentChannel;
use App\Models\Product;
use App\Models\ProductItem;
use App\Models\Transaction;
use App\Services\Supplier\SupplierInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminPanelTest extends TestCase
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

    public function test_dashboard_renders_with_statistics(): void
    {
        $this->loginAdmin();

        $response = $this->get('/admin/dashboard');

        $response->assertStatus(200);
        $response->assertSee('Transaksi Hari Ini');
        $response->assertSee('Revenue Hari Ini');
    }

    public function test_admin_create_and_edit_form_pages_render(): void
    {
        $this->loginAdmin();

        $category = Category::create(['name' => 'Games', 'slug' => 'games']);
        $product = Product::create(['category_id' => $category->id, 'name' => 'ML', 'slug' => 'ml']);
        $item = ProductItem::create(['product_id' => $product->id, 'name' => '86 DM', 'sell_price' => '15000']);
        $banner = Banner::create(['title' => 'Promo', 'image' => 'https://x.test/b.jpg']);
        $coupon = Coupon::create(['code' => 'HEMAT', 'discount_type' => 'percentage', 'discount_value' => '10']);

        // Halaman create.
        $this->get('/admin/categories/create')->assertStatus(200)->assertSee('Buat Kategori');
        $this->get('/admin/products/create')->assertStatus(200)->assertSee('Buat Produk');
        $this->get('/admin/product-items/create')->assertStatus(200)->assertSee('Buat Item');
        $this->get('/admin/banners/create')->assertStatus(200)->assertSee('Buat Banner');
        $this->get('/admin/coupons/create')->assertStatus(200)->assertSee('Buat Kupon');

        // Halaman edit.
        $this->get("/admin/categories/{$category->id}/edit")->assertStatus(200)->assertSee('Simpan Perubahan');
        $this->get("/admin/products/{$product->id}/edit")->assertStatus(200)->assertSee('Input Fields');
        $this->get("/admin/product-items/{$item->id}/edit")->assertStatus(200)->assertSee('Flash Sale');
        $this->get("/admin/banners/{$banner->id}/edit")->assertStatus(200)->assertSee('Simpan Perubahan');
        $this->get("/admin/coupons/{$coupon->id}/edit")->assertStatus(200)->assertSee('Simpan Perubahan');

        // Halaman index lainnya.
        $this->get('/admin/transactions')->assertStatus(200);
        $this->get('/admin/banners')->assertStatus(200);
        $this->get('/admin/coupons')->assertStatus(200);
        $this->get('/admin/settings')->assertStatus(200)->assertSee('Pengaturan');
    }

    public function test_admin_can_create_category(): void
    {
        $this->loginAdmin();

        $response = $this->post('/admin/categories', [
            'name' => 'Voucher',
            'slug' => 'voucher',
            'icon' => '🎫',
            'is_active' => '1',
        ]);

        $response->assertRedirect(route('admin.categories.index'));
        $this->assertDatabaseHas('categories', ['slug' => 'voucher', 'name' => 'Voucher']);
    }

    public function test_unchecked_popular_checkbox_clears_existing_popular_flag(): void
    {
        $this->loginAdmin();

        $category = Category::create(['name' => 'Games', 'slug' => 'games']);
        $product = Product::create([
            'category_id' => $category->id,
            'name' => 'Free Fire',
            'slug' => 'free-fire',
            'is_popular' => true,
        ]);

        $response = $this->put("/admin/products/{$product->id}", [
            'category_id' => $category->id,
            'name' => 'Free Fire',
            'slug' => 'free-fire',
            'input_fields' => '[]',
            'is_active' => '1',
        ]);

        $response->assertRedirect(route('admin.products.index'));
        $this->assertDatabaseHas('products', ['id' => $product->id, 'is_popular' => false]);
    }

    public function test_admin_can_create_product_with_input_fields(): void
    {
        $this->loginAdmin();

        $category = Category::create(['name' => 'Games', 'slug' => 'games']);

        $response = $this->post('/admin/products', [
            'category_id' => $category->id,
            'name' => 'Free Fire',
            'slug' => 'free-fire',
            'supplier_type' => 'digiflazz',
            'supplier_code' => 'free-fire',
            'input_fields' => json_encode([
                ['name' => 'user_id', 'label' => 'Player ID', 'type' => 'text', 'required' => true, 'placeholder' => 'Masukkan Player ID'],
            ]),
            'is_active' => '1',
        ]);

        $response->assertRedirect(route('admin.products.index'));

        $product = Product::query()->where('slug', 'free-fire')->first();

        $this->assertNotNull($product);
        $this->assertSame('user_id', $product->input_fields[0]['name']);
        $this->assertTrue($product->input_fields[0]['required']);
    }

    public function test_admin_can_update_payment_channel_fee_and_toggle(): void
    {
        $this->loginAdmin();

        $channel = PaymentChannel::create([
            'gateway' => 'tripay',
            'code' => 'QRIS',
            'name' => 'QRIS',
            'group' => 'QRIS',
            'flat_fee' => '0',
            'percent_fee' => '0.70',
            'is_active' => true,
        ]);

        // Update fee.
        $this->put("/admin/payment-channels/{$channel->id}", [
            'name' => 'QRIS',
            'group' => 'QRIS',
            'flat_fee' => '500',
            'percent_fee' => '1',
            'is_active' => '1',
        ]);

        $this->assertDatabaseHas('payment_channels', ['id' => $channel->id, 'flat_fee' => '500.00', 'percent_fee' => '1.00']);

        // Toggle nonaktif.
        $this->post("/admin/payment-channels/{$channel->id}/toggle");
        $this->assertDatabaseHas('payment_channels', ['id' => $channel->id, 'is_active' => false]);
    }

    public function test_admin_can_retry_fulfillment_and_mark_completed(): void
    {
        $this->loginAdmin();

        $category = Category::create(['name' => 'Games', 'slug' => 'games']);
        $product = Product::create(['category_id' => $category->id, 'name' => 'ML', 'slug' => 'ml', 'supplier_type' => 'digiflazz', 'supplier_code' => 'ml']);
        $item = ProductItem::create(['product_id' => $product->id, 'name' => '86 DM', 'supplier_item_code' => 'ml-86', 'sell_price' => '15000']);

        $transaction = Transaction::create([
            'invoice_id' => 'INV-RETRY-001',
            'product_item_id' => $item->id,
            'customer_data' => ['user_id' => '123'],
            'customer_phone' => '081234567890',
            'amount' => '15000',
            'fee' => '0',
            'total' => '15000',
            'profit' => '0',
            'status' => 'paid',
            'paid_at' => now(),
        ]);

        // Mock supplier supaya retry tidak memanggil API eksternal.
        $supplier = $this->mock(SupplierInterface::class);
        $supplier->shouldReceive('topup')->once()->andReturn([
            'supplier_ref' => 'DF-1',
            'supplier_sn' => 'SN-1',
            'status' => 'processing',
            'raw' => [],
        ]);
        $this->app->instance(SupplierInterface::class, $supplier);

        $response = $this->post("/admin/transactions/{$transaction->id}/retry-fulfillment");
        $response->assertRedirect();

        $this->assertDatabaseHas('transactions', ['id' => $transaction->id, 'status' => 'processing']);

        // Mark completed.
        $response = $this->post("/admin/transactions/{$transaction->id}/mark-completed");
        $response->assertRedirect();

        $this->assertDatabaseHas('transactions', ['id' => $transaction->id, 'status' => 'completed']);
        $this->assertNotNull($transaction->fresh()->completed_at);
    }

    public function test_admin_settings_can_be_saved(): void
    {
        $this->loginAdmin();

        $response = $this->put('/admin/settings', [
            'settings' => [
                'site_name' => 'CaptShop Test',
                'contact_whatsapp' => '628999000111',
                'digiflazz_username' => 'userdf',
            ],
        ]);

        $response->assertRedirect();

        $this->assertDatabaseHas('site_settings', ['key' => 'site_name', 'value' => 'CaptShop Test']);
        $this->assertDatabaseHas('site_settings', ['key' => 'contact_whatsapp', 'value' => '628999000111']);
        $this->assertDatabaseHas('site_settings', ['key' => 'digiflazz_username', 'value' => 'userdf']);
    }

    /**
     * Regression: form browser mengirim POST + _method=PUT (method spoofing).
     * Tanpa @method('PUT') di blade, submit form nyata melempar
     * MethodNotAllowedHttpException — test put() biasa tidak menangkapnya.
     */
    public function test_admin_settings_form_submits_via_method_spoofing(): void
    {
        $this->loginAdmin();

        $response = $this->post('/admin/settings', [
            '_method' => 'PUT',
            'settings' => [
                'site_name' => 'CaptShop Spoofed',
            ],
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('site_settings', ['key' => 'site_name', 'value' => 'CaptShop Spoofed']);
    }
}
