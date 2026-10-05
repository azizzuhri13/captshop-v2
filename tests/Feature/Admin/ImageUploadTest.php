<?php

namespace Tests\Feature\Admin;

use App\Models\Admin;
use App\Models\Banner;
use App\Models\Category;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ImageUploadTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Binary PNG 1x1 piksel yang valid — dipakai karena ekstensi GD
     * tidak tersedia di lingkungan ini (UploadedFile::fake()->image()
     * membutuhkan GD).
     */
    protected const PNG_1X1 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

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

    protected function fakeImage(string $name): UploadedFile
    {
        $path = sys_get_temp_dir().'/'.uniqid('img_').'.png';
        file_put_contents($path, base64_decode(self::PNG_1X1));

        return new UploadedFile($path, $name, 'image/png', test: true);
    }

    protected function bannerPayload(array $overrides = []): array
    {
        return array_merge([
            'title' => 'Promo Gajian',
            'type' => 'carousel',
            'sort_order' => 1,
            'is_active' => '1',
        ], $overrides);
    }

    public function test_banner_create_stores_uploaded_image_in_public_disk(): void
    {
        $this->loginAdmin();
        Storage::fake('public');

        $response = $this->post('/admin/banners', $this->bannerPayload() + [
            'image' => $this->fakeImage('banner.jpg'),
        ]);

        $response->assertRedirect(route('admin.banners.index'));

        $banner = Banner::query()->first();

        $this->assertNotNull($banner);
        $this->assertStringStartsWith('banners/', $banner->image);

        // File tersimpan di disk public dan path bisa diakses browser (/storage/...).
        Storage::disk('public')->assertExists($banner->image);
        $this->assertStringContainsString('/storage/banners/', $banner->imageUrl());

        // URL harus dibangun via asset() (mengikuti host/port request),
        // bukan APP_URL statis — mencegah gambar rusak saat serve di port lain.
        $this->assertSame(asset('storage/'.$banner->image), $banner->imageUrl());
    }

    public function test_banner_update_replaces_image_and_deletes_old_file(): void
    {
        $this->loginAdmin();
        Storage::fake('public');

        Storage::disk('public')->put($oldPath = 'banners/old-banner.png', base64_decode(self::PNG_1X1));

        $banner = Banner::create($this->bannerPayload() + ['image' => $oldPath]);

        $response = $this->post("/admin/banners/{$banner->id}", $this->bannerPayload([
            'title' => 'Promo Gajian v2',
        ]) + [
            '_method' => 'PUT',
            'image' => $this->fakeImage('banner-new.png'),
        ]);

        $response->assertRedirect(route('admin.banners.index'));

        $banner->refresh();

        // File baru tersimpan, file lama terhapus.
        $this->assertNotSame($oldPath, $banner->image);
        Storage::disk('public')->assertExists($banner->image);
        Storage::disk('public')->assertMissing($oldPath);

        $this->assertDatabaseHas('banners', ['id' => $banner->id, 'title' => 'Promo Gajian v2']);
    }

    public function test_banner_delete_removes_image_file(): void
    {
        $this->loginAdmin();
        Storage::fake('public');

        Storage::disk('public')->put($path = 'banners/banner.png', base64_decode(self::PNG_1X1));

        $banner = Banner::create($this->bannerPayload() + ['image' => $path]);

        $response = $this->post("/admin/banners/{$banner->id}", ['_method' => 'DELETE']);

        $response->assertRedirect(route('admin.banners.index'));

        $this->assertDatabaseMissing('banners', ['id' => $banner->id]);
        Storage::disk('public')->assertMissing($path);
    }

    public function test_banner_rejects_non_image_file(): void
    {
        $this->loginAdmin();
        Storage::fake('public');

        $file = UploadedFile::fake()->create('dokumen.pdf', 100, 'application/pdf');

        $response = $this->post('/admin/banners', $this->bannerPayload() + [
            'image' => $file,
        ]);

        $response->assertSessionHasErrors('image');
        $this->assertSame(0, Banner::count());
    }

    public function test_banner_rejects_oversized_image(): void
    {
        $this->loginAdmin();
        Storage::fake('public');

        // File berformat PNG valid namun > 2048 KB.
        $bigPath = sys_get_temp_dir().'/'.uniqid('big_').'.png';
        file_put_contents($bigPath, base64_decode(self::PNG_1X1).str_repeat('0', 2049 * 1024));

        $response = $this->post('/admin/banners', $this->bannerPayload() + [
            'image' => new UploadedFile($bigPath, 'banner.png', 'image/png', test: true),
        ]);

        $response->assertSessionHasErrors('image');
        $this->assertSame(0, Banner::count());
    }

    public function test_product_create_and_update_with_icon_upload(): void
    {
        $this->loginAdmin();
        Storage::fake('public');

        $category = Category::create(['name' => 'Games', 'slug' => 'games']);

        $response = $this->post('/admin/products', [
            'category_id' => $category->id,
            'name' => 'Mobile Legends',
            'slug' => 'mobile-legends',
            'icon' => $this->fakeImage('icon.png'),
            'banner' => $this->fakeImage('banner.png'),
            'is_active' => '1',
            'input_fields' => json_encode([['name' => 'user_id', 'label' => 'User ID', 'type' => 'text', 'required' => true]]),
        ]);

        $response->assertRedirect(route('admin.products.index'));

        $product = Product::query()->where('slug', 'mobile-legends')->first();

        $this->assertNotNull($product);
        $this->assertStringStartsWith('products/', $product->icon);
        $this->assertStringStartsWith('products/', $product->banner);
        Storage::disk('public')->assertExists($product->icon);
        Storage::disk('public')->assertExists($product->banner);
        $this->assertStringContainsString('/storage/products/', $product->iconUrl());

        // Ganti ikon: file lama terhapus, banner lama tetap.
        $oldIcon = $product->icon;
        $oldBanner = $product->banner;

        $this->post("/admin/products/{$product->id}", [
            '_method' => 'PUT',
            'category_id' => $category->id,
            'name' => 'Mobile Legends',
            'slug' => 'mobile-legends',
            'icon' => $this->fakeImage('icon-baru.png'),
            'is_active' => '1',
            'input_fields' => json_encode([['name' => 'user_id', 'label' => 'User ID', 'type' => 'text', 'required' => true]]),
        ]);

        $product->refresh();

        $this->assertNotSame($oldIcon, $product->icon);
        Storage::disk('public')->assertExists($product->icon);
        Storage::disk('public')->assertMissing($oldIcon);

        // Banner tidak diupload ulang → tidak berubah.
        $this->assertSame($oldBanner, $product->banner);
        Storage::disk('public')->assertExists($oldBanner);
    }

    public function test_product_delete_removes_image_files(): void
    {
        $this->loginAdmin();
        Storage::fake('public');

        $category = Category::create(['name' => 'Games', 'slug' => 'games']);

        Storage::disk('public')->put($iconPath = 'products/icon.png', base64_decode(self::PNG_1X1));
        Storage::disk('public')->put($bannerPath = 'products/banner.png', base64_decode(self::PNG_1X1));

        $product = Product::create([
            'category_id' => $category->id,
            'name' => 'Free Fire',
            'slug' => 'free-fire',
            'icon' => $iconPath,
            'banner' => $bannerPath,
        ]);

        $response = $this->post("/admin/products/{$product->id}", ['_method' => 'DELETE']);

        $response->assertRedirect(route('admin.products.index'));

        $this->assertDatabaseMissing('products', ['id' => $product->id]);
        Storage::disk('public')->assertMissing($iconPath);
        Storage::disk('public')->assertMissing($bannerPath);
    }
}
