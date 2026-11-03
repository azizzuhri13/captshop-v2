<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\SiteSetting;
use App\Support\Credentials;
use App\Support\PaymentGateway;
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

        // Integrasi — Tripay (secret tersimpan terpisah dari tampilan)
        'tripay_mode' => 'Tripay Mode (sandbox / live)',
        'tripay_merchant_code' => 'Tripay Merchant Code (mis. T0001)',
        'tripay_api_key' => 'Tripay API Key',
        'tripay_private_key' => 'Tripay Private Key',

        // Integrasi — Supplier (DigiFlazz)
        'supplier_default' => 'Supplier Default (digiflazz / tokovoucher)',
        'digiflazz_username' => 'DigiFlazz Username',
        'digiflazz_api_key' => 'DigiFlazz API Key',
        'digiflazz_base_url' => 'DigiFlazz Base URL',

        // Integrasi — Supplier (Tokovoucher / Apigames)
        'tokovoucher_member_code' => 'Tokovoucher Member Code',
        'tokovoucher_secret_key' => 'Tokovoucher Secret Key',
        'tokovoucher_signature' => 'Tokovoucher Signature Default (Opsional)',
        'tokovoucher_base_url' => 'Tokovoucher Base URL',

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
        'tripay_api_key',
        'tripay_private_key',
        'digiflazz_api_key',
        'tokovoucher_secret_key',
        'tokovoucher_signature',
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
            'paymentGateway' => PaymentGateway::effective(),
            'paymentGatewayStored' => PaymentGateway::storedChoice(),
            'tripayReadiness' => PaymentGateway::tripayReadiness(),
            'tripayCallbackUrl' => (string) config('payment.tripay.callback_url'),
            'tripayMode' => strtolower(trim((string) Credentials::get('tripay_mode', config('payment.tripay.mode', 'sandbox')))),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'settings' => ['nullable', 'array'],
            'settings.*' => ['nullable', 'string', 'max:1000'],
            'clear_settings' => ['nullable', 'array'],
            'clear_settings.*' => ['boolean'],
            // Kode merchant Belibayar berformat BYR + 6 karakter (mis. BYR670455).
            // Validasi hanya untuk nilai baru agar nilai lama yang salah ketik
            // tidak memblokir penyimpanan setting lain; perbaikannya tetap dipaksa
            // saat field ini diubah.
            'settings.belibayar_merchant_id' => [
                'nullable', 'string',
                function (string $attribute, mixed $value, \Closure $fail) {
                    $value = trim((string) $value);

                    if ($value === '' || $value === SiteSetting::get('belibayar_merchant_id')) {
                        return;
                    }

                    if (! preg_match('/^BYR[A-Z0-9]{6}$/', $value)) {
                        $fail('Belibayar Merchant ID harus berformat BYR diikuti 6 karakter, misalnya BYR670455.');
                    }
                },
            ],
            // Mode Tripay hanya dua nilai resmi. Nilai lain akan membuat
            // client menolak semua request, jadi tolak lebih awal di form.
            'settings.tripay_mode' => ['nullable', 'string', 'in:sandbox,live'],
            // Merchant code Tripay berformat alfanumerik tanpa spasi
            // (mis. T0001). Validasi hanya untuk nilai baru agar nilai lama
            // yang salah ketik tidak memblokir penyimpanan setting lain.
            'settings.tripay_merchant_code' => [
                'nullable', 'string', 'max:64',
                function (string $attribute, mixed $value, \Closure $fail) {
                    $value = trim((string) $value);

                    if ($value === '' || $value === SiteSetting::get('tripay_merchant_code')) {
                        return;
                    }

                    if (preg_match('/^[A-Za-z0-9]{3,32}\z/', $value) !== 1) {
                        $fail('Tripay Merchant Code harus 3-32 karakter alfanumerik tanpa spasi, misalnya T0001.');
                    }
                },
            ],
            // Secret Tripay: tolak spasi/newline/karakter kontrol dan nilai
            // terlalu pendek/panjang. Masker bullet (••••) tetap lolos dan
            // diabaikan oleh loop penyimpanan, sama seperti secret lain.
            'settings.tripay_api_key' => [
                'nullable', 'string', 'max:512',
                function (string $attribute, mixed $value, \Closure $fail) {
                    $value = (string) $value;

                    if ($value === '') {
                        return;
                    }

                    if (preg_match('/^[^\s]{8,512}\z/u', $value) !== 1) {
                        $fail('Tripay API Key tidak valid: 8-512 karakter tanpa spasi/baris baru.');
                    }
                },
            ],
            'settings.tripay_private_key' => [
                'nullable', 'string', 'max:512',
                function (string $attribute, mixed $value, \Closure $fail) {
                    $value = (string) $value;

                    if ($value === '') {
                        return;
                    }

                    if (preg_match('/^[^\s]{8,512}\z/u', $value) !== 1) {
                        $fail('Tripay Private Key tidak valid: 8-512 karakter tanpa spasi/baris baru.');
                    }
                },
            ],
            // Saklar gateway punya aksi sendiri (updateGateway) yang memeriksa
            // readiness dan menulis audit log. Menolak key ini di form umum
            // menutup jalur tulis kedua: halaman lama yang masih memuat input
            // duplikat `settings[payment_gateway]` tidak bisa memindahkan
            // gateway tanpa guard, dan satu-satunya sumber kebenaran tetap
            // route admin.settings.payment-gateway.
            'settings.payment_gateway' => ['prohibited'],
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

    /**
     * Saklar gateway eksplisit (belibayar ↔ tripay).
     *
     * Tripay hanya boleh diaktifkan bila kredensial, channel aktif, dan
     * callback URL sudah siap. Mengisi kredensial saja tidak pernah
     * memindahkan gateway. Kembali ke Belibayar selalu diizinkan sebagai
     * jalur rollback.
     */
    public function updateGateway(Request $request): RedirectResponse
    {
        // Normalisasi sebelum validasi: spasi/huruf besar dari form tidak boleh
        // lolos menjadi gateway "baru" yang tidak dikenal. Nilai selain dua
        // gateway resmi tetap ditolak (fail-closed), bukan dipetakan diam-diam.
        $request->merge([
            'gateway' => strtolower(trim((string) $request->input('gateway'))),
        ]);

        $validated = $request->validate([
            'gateway' => ['required', 'string', 'in:'.PaymentGateway::BELIBAYAR.','.PaymentGateway::TRIPAY],
        ]);

        $target = strtolower(trim($validated['gateway']));
        $current = PaymentGateway::storedChoice() ?? PaymentGateway::effective();

        if ($target === PaymentGateway::TRIPAY) {
            $readiness = PaymentGateway::tripayReadiness();

            if (! $readiness['ready']) {
                return back()->withErrors([
                    'gateway' => 'Tripay belum bisa diaktifkan: '.implode(' ', $readiness['missing']),
                ]);
            }
        }

        // Hanya dua nilai resmi yang bisa tersimpan; apa pun selain Tripay
        // berarti Belibayar, sehingga saklar tidak pernah menyimpan nilai yang
        // membuat effective() menebak-nebak.
        $target = $target === PaymentGateway::TRIPAY ? PaymentGateway::TRIPAY : PaymentGateway::BELIBAYAR;

        SiteSetting::updateOrCreate(
            ['key' => PaymentGateway::SETTING_KEY],
            ['value' => $target]
        );

        AuditLog::record(
            auth('admin')->user(),
            'update',
            'SiteSetting',
            null,
            ['payment_gateway' => $current],
            ['payment_gateway' => $target]
        );

        return back()->with('success', $target === PaymentGateway::TRIPAY
            ? 'Gateway aktif: Tripay. Pesanan baru akan memakai Tripay.'
            : 'Gateway aktif: Belibayar.');
    }
}
