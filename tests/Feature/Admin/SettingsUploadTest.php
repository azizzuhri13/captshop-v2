<?php

namespace Tests\Feature\Admin;

use App\Models\Admin;
use App\Models\SiteSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class SettingsUploadTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Binary PNG 1x1 piksel yang valid — dipakai karena ekstensi GD
     * tidak tersedia di lingkungan ini (UploadedFile::fake()->image()
     * membutuhkan GD).
     */
    protected const PNG_1X1 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

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

    protected function fakeImage(string $name, string $mime = 'image/png'): UploadedFile
    {
        $path = sys_get_temp_dir().'/'.uniqid('img_').'.png';
        file_put_contents($path, base64_decode(self::PNG_1X1));

        return new UploadedFile($path, $name, $mime, test: true);
    }

    public function test_logo_upload_stores_file_and_updates_setting(): void
    {
        Storage::fake('public');
        $this->actingAs($this->superAdmin(), 'admin');

        $this->put(route('admin.settings.update'), [
            'settings' => ['site_name' => 'CaptShop Upload'],
            'site_logo' => $this->fakeImage('logo.png'),
            'site_favicon' => $this->fakeImage('favicon.png'),
        ])->assertRedirect();

        $logo = SiteSetting::get('site_logo');
        $favicon = SiteSetting::get('site_favicon');

        $this->assertNotNull($logo);
        $this->assertNotNull($favicon);
        $this->assertStringStartsWith('/storage/settings/', $logo);
        $this->assertStringStartsWith('/storage/settings/', $favicon);

        Storage::disk('public')->assertExists(substr($logo, strlen('/storage/')));
        Storage::disk('public')->assertExists(substr($favicon, strlen('/storage/')));
    }

    public function test_logo_reupload_replaces_old_file(): void
    {
        Storage::fake('public');
        $this->actingAs($this->superAdmin(), 'admin');

        $this->put(route('admin.settings.update'), [
            'site_logo' => $this->fakeImage('logo-lama.png'),
        ])->assertRedirect();

        $oldPath = substr((string) SiteSetting::get('site_logo'), strlen('/storage/'));

        $this->put(route('admin.settings.update'), [
            'site_logo' => $this->fakeImage('logo-baru.png'),
        ])->assertRedirect();

        $newPath = substr((string) SiteSetting::get('site_logo'), strlen('/storage/'));

        $this->assertNotSame($oldPath, $newPath);
        Storage::disk('public')->assertMissing($oldPath);
        Storage::disk('public')->assertExists($newPath);
    }

    public function test_external_url_value_is_not_touched_on_upload(): void
    {
        Storage::fake('public');
        $this->actingAs($this->superAdmin(), 'admin');

        // Nilai lama berupa URL eksternal (sisa input teks zaman dulu) —
        // tidak boleh dihapus dari disk (memang bukan file lokal).
        SiteSetting::set('site_logo', 'https://cdn.example.com/logo.png');

        $this->put(route('admin.settings.update'), [
            'site_logo' => $this->fakeImage('logo.png'),
        ])->assertRedirect();

        $this->assertStringStartsWith('/storage/settings/', (string) SiteSetting::get('site_logo'));
    }

    public function test_saving_other_settings_keeps_existing_logo(): void
    {
        Storage::fake('public');
        $this->actingAs($this->superAdmin(), 'admin');

        $this->put(route('admin.settings.update'), [
            'site_logo' => $this->fakeImage('logo.png'),
        ])->assertRedirect();

        $logo = SiteSetting::get('site_logo');

        // Submit tanpa unggahan baru: nilai logo tidak boleh ter-reset.
        $this->put(route('admin.settings.update'), [
            'settings' => ['site_name' => 'Nama Baru Saja'],
        ])->assertRedirect();

        $this->assertSame($logo, SiteSetting::get('site_logo'));
        $this->assertSame('Nama Baru Saja', SiteSetting::get('site_name'));
        Storage::disk('public')->assertExists(substr((string) $logo, strlen('/storage/')));
    }

    public function test_rejects_non_image_upload(): void
    {
        Storage::fake('public');
        $this->actingAs($this->superAdmin(), 'admin');

        $file = UploadedFile::fake()->create('logo.txt', 1, 'text/plain');

        $this->put(route('admin.settings.update'), [
            'site_logo' => $file,
        ])->assertSessionHasErrors('site_logo');

        $this->assertNull(SiteSetting::get('site_logo'));
        $this->assertSame([], Storage::disk('public')->files('settings'));
    }

    public function test_settings_page_shows_current_logo_preview(): void
    {
        $this->actingAs($this->superAdmin(), 'admin');

        SiteSetting::set('site_logo', '/storage/settings/preview-logo.png');

        $response = $this->get(route('admin.settings.edit'));

        $response->assertOk()
            ->assertSee('preview-logo.png')
            ->assertSee('type="file"', false);
    }
}
