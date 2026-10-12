<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\SiteSetting;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;

class SettingController extends Controller
{
    /**
     * Daftar setting yang dikelola: [key => label].
     */
    protected const SETTINGS = [
        // Situs
        'site_name' => 'Nama Situs',
        'site_description' => 'Deskripsi Situs (SEO)',
        'site_logo' => 'Logo Situs (gambar)',
        'site_favicon' => 'Favicon (gambar)',

        // Kontak
        'contact_whatsapp' => 'WhatsApp CS (62xxx)',
        'contact_email' => 'Email CS',
        'contact_instagram' => 'Instagram (opsional)',
        'contact_facebook' => 'Facebook (opsional)',

        // Maintenance
        'maintenance_mode' => 'Maintenance Mode (true/false)',

        // Integrasi — Belibayar (secret tersimpan terpisah dari tampilan)
        'belibayar_api_key' => 'Belibayar API Key',
        'belibayar_secret_key' => 'Belibayar Secret Key',
        'belibayar_webhook_secret' => 'Belibayar Webhook Secret',
        'belibayar_merchant_id' => 'Belibayar Merchant ID',

        // Integrasi — Supplier (DigiFlazz)
        'digiflazz_username' => 'DigiFlazz Username',
        'digiflazz_api_key' => 'DigiFlazz API Key',
        'digiflazz_base_url' => 'DigiFlazz Base URL',

        // Integrasi — WhatsApp Gateway (Fonnte)
        'fonnte_token' => 'Fonnte Token',
        'fonnte_base_url' => 'Fonnte Base URL',
    ];

    /**
     * Setting berupa kredensial: tidak boleh dikirim kembali ke browser
     * (anti kebocoran via page source) dan hanya diperbarui bila diisi
     * ulang — input kosong berarti mempertahankan nilai tersimpan.
     */
    protected const SECRET_KEYS = [
        'belibayar_api_key',
        'belibayar_secret_key',
        'belibayar_webhook_secret',
        'digiflazz_api_key',
        'fonnte_token',
    ];

    /**
     * Setting berupa unggahan file (logo & favicon) — dikelola terpisah
     * dari field teks: diunggah ke disk public, nilai tersimpan berupa
     * path publik /storage/settings/... bukan URL bebas.
     */
    protected const FILE_KEYS = [
        'site_logo',
        'site_favicon',
    ];

    /**
     * Prefix path unggahan pada disk public.
     */
    protected const UPLOAD_DIR = 'settings';

    public function edit(): View
    {
        $settings = SiteSetting::query()
            ->whereIn('key', array_keys(self::SETTINGS))
            ->pluck('value', 'key');

        return view('admin.settings.edit', [
            'settings' => $settings,
            'schema' => self::SETTINGS,
            'secretKeys' => self::SECRET_KEYS,
            'fileKeys' => self::FILE_KEYS,
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'settings' => ['nullable', 'array'],
            'settings.*' => ['nullable', 'string', 'max:1000'],
            'clear_settings' => ['nullable', 'array'],
            'clear_settings.*' => ['boolean'],
            // Rule `image` Laravel tidak mengenali .ico, jadi validasi mime
            // dilakukan eksplisit agar favicon .ico tetap diterima. SVG dilarang untuk mencegah Stored XSS.
            'site_logo' => ['nullable', 'file', 'mimes:png,jpg,jpeg,gif,webp,ico', 'max:2048'],
            'site_favicon' => ['nullable', 'file', 'mimes:png,jpg,jpeg,gif,webp,ico', 'max:2048'],
        ]);

        $changes = [];

        // Unggahan logo & favicon: simpan ke disk public, hapus file lama
        // (hanya bila tersimpan sebagai file lokal, bukan URL eksternal).
        foreach (self::FILE_KEYS as $key) {
            if (! $request->hasFile($key)) {
                continue;
            }

            $oldValue = SiteSetting::get($key);

            $path = $request->file($key)->store(self::UPLOAD_DIR, 'public');
            $publicPath = '/storage/'.$path;

            if (is_string($oldValue) && str_starts_with($oldValue, '/storage/'.self::UPLOAD_DIR.'/')) {
                Storage::disk('public')->delete(substr($oldValue, strlen('/storage/')));
            }

            SiteSetting::updateOrCreate(['key' => $key], ['value' => $publicPath]);
            $changes[$key] = $publicPath;
        }

        foreach (array_keys(self::SETTINGS) as $key) {
            if (in_array($key, self::FILE_KEYS, true)) {
                // Nilainya dikelola via unggahan file di atas; tanpa file baru,
                // nilai tersimpan dipertahankan.
                continue;
            }

            $oldValue = SiteSetting::get($key);

            if (in_array($key, self::SECRET_KEYS, true)) {
                // Kredensial: input kosong = tidak diubah; checkbox clear = hapus
                // override agar kembali memakai nilai dari .env.
                if ($request->boolean('clear_settings.'.$key)) {
                    SiteSetting::query()->where('key', $key)->delete();
                    $changes[$key] = '[dihapus — kembali ke .env]';

                    continue;
                }

                $value = trim((string) ($validated['settings'][$key] ?? ''));
                $value = str_replace(["\r", "\n"], '', $value);

                // Abaikan input kosong atau yang hanya berisi karakter mask pelindung (seperti • atau *)
                if ($value !== '' && ! preg_match('/^[\x{2022}\*•\s]+$/u', $value)) {
                    SiteSetting::updateOrCreate(['key' => $key], ['value' => $value]);
                    $changes[$key] = '[tersembunyi]';
                }

                continue;
            }

            $value = trim((string) ($validated['settings'][$key] ?? ''));

            SiteSetting::updateOrCreate(
                ['key' => $key],
                ['value' => $value === '' ? null : $value]
            );

            if ((string) ($oldValue ?? '') !== ($value === '' ? '' : $value)) {
                $changes[$key] = $value === '' ? null : $value;
            }
        }

        AuditLog::record(auth('admin')->user(), 'update', 'SiteSetting', null, null, $changes);

        return back()->with('success', 'Pengaturan berhasil disimpan.');
    }
}
