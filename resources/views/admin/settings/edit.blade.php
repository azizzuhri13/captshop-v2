@extends('layouts.admin')

@section('title', 'Pengaturan')
@section('page-title', 'Pengaturan')

@section('content')
    <form action="{{ route('admin.settings.update') }}" method="POST" enctype="multipart/form-data" class="space-y-6">
        @csrf
        @method('PUT')

        @foreach ([
            'Situs' => ['site_name', 'site_description', 'site_logo', 'site_favicon', 'maintenance_mode'],
            'Kontak' => ['contact_whatsapp', 'contact_email', 'contact_instagram', 'contact_facebook'],
        ] as $sectionTitle => $keys)
            <section class="rounded-xl border border-slate-800 bg-slate-800/40 p-5">
                <h2 class="mb-4 font-heading text-sm font-bold text-slate-200">{{ $sectionTitle }}</h2>

                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    @foreach ($keys as $key)
                        <div @class(['min-w-0', 'sm:col-span-2' => in_array($key, ['site_description'])])>
                            <label class="mb-1.5 block text-sm font-medium text-slate-300">{{ $schema[$key] }}</label>

                            @if (in_array($key, $fileKeys))
                                {{-- Unggahan file (logo & favicon) --}}
                                @if (! empty($settings[$key]))
                                    <div class="mb-2 flex min-w-0 w-full items-center gap-3 rounded-lg border border-slate-700 bg-slate-900/60 px-3 py-2">
                                        <img src="{{ $settings[$key] }}" alt="Pratinjau {{ $schema[$key] }}" class="{{ $key === 'site_favicon' ? 'h-8 w-8' : 'h-10 w-10' }} shrink-0 rounded-md bg-slate-800 object-contain p-1">
                                        <span class="min-w-0 truncate text-[11px] text-slate-500" title="{{ $settings[$key] }}">Saat ini: {{ $settings[$key] }}</span>
                                    </div>
                                @endif
                                <input
                                    type="file"
                                    name="{{ $key }}"
                                    accept="image/png,image/jpeg,image/gif,image/webp,.ico"
                                    class="block w-full text-sm text-slate-400 file:mr-3 file:cursor-pointer file:rounded-lg file:border-0 file:bg-primary file:px-3 file:py-2 file:font-medium file:text-white hover:file:bg-primary-dark"
                                >
                                <p class="mt-1 text-[11px] text-slate-600">PNG, JPG, GIF, WebP, atau ICO — maks. 2 MB. Biarkan kosong untuk mempertahankan yang sekarang.</p>
                                @error($key)<p class="mt-1.5 text-xs text-red-400">{{ $message }}</p>@enderror
                            @elseif ($key === 'site_description')
                                <textarea name="settings[{{ $key }}]" rows="2" class="input-dark">{{ old('settings.'.$key, $settings[$key] ?? '') }}</textarea>
                            @else
                                <input type="text" name="settings[{{ $key }}]" value="{{ old('settings.'.$key, $settings[$key] ?? '') }}" class="input-dark">
                            @endif
                        </div>
                    @endforeach
                </div>
            </section>
        @endforeach

        {{-- Catatan: blok Tripay TIDAK didaftarkan di loop ini. Panel Tripay
             punya section khusus di bawah (mode, merchant code, secret,
             callback, kesiapan). Mendaftarkannya di sini akan menghasilkan
             dua input dengan name yang sama, dan input terakhir menang saat
             submit — nilai mode/merchant bisa tertimpa diam-diam. --}}
        @foreach ([
            'Payment Gateway (Belibayar)' => ['belibayar_api_key', 'belibayar_secret_key', 'belibayar_webhook_secret', 'belibayar_merchant_id'],
            '🔌 Supplier Utama (Default)' => ['supplier_default'],
            '🔌 Supplier (DigiFlazz)' => ['digiflazz_username', 'digiflazz_api_key', 'digiflazz_base_url'],
            '🔌 Supplier (Tokovoucher / Apigames)' => ['tokovoucher_member_code', 'tokovoucher_secret_key', 'tokovoucher_signature', 'tokovoucher_base_url'],
        ] as $sectionTitle => $keys)
            <section class="rounded-xl border border-primary/30 bg-primary/5 p-5">
                <h2 class="mb-4 font-heading text-sm font-bold text-slate-200">{{ $sectionTitle }}</h2>

                <div class="grid gap-4 sm:grid-cols-2">
                    @foreach ($keys as $key)
                        <div>
                            <label class="mb-1.5 block text-sm font-medium text-slate-300">{{ $schema[$key] }}</label>
                            @if (in_array($key, $secretKeys, true))
                                <div x-data="{ show: false }" class="space-y-1">
                                    <div class="relative">
                                        <input
                                            :type="show ? 'text' : 'password'"
                                            name="settings[{{ $key }}]"
                                            class="input-dark pr-10 font-mono text-xs"
                                            autocomplete="off"
                                            placeholder="{{ filled($settings[$key] ?? null) ? 'Terisi — kosongkan untuk mempertahankan nilai' : 'Masukkan ' . $schema[$key] }}"
                                        >
                                        <button
                                            type="button"
                                            @click="show = !show"
                                            class="absolute inset-y-0 right-0 flex items-center pr-3 text-slate-400 hover:text-slate-200"
                                            tabindex="-1"
                                            title="Lihat / Sembunyikan"
                                        >
                                            <span x-show="!show" class="text-xs">👁️</span>
                                            <span x-show="show" class="text-xs" x-cloak>🙈</span>
                                        </button>
                                    </div>
                                    @if (filled($settings[$key] ?? null))
                                        <div class="flex items-center justify-between text-[11px] text-slate-400">
                                            {{-- Penanda saja: panjang/akhiran kredensial tidak pernah
                                                 dikirim ke browser karena bisa menyempitkan tebakan. --}}
                                            <span class="flex items-center gap-1.5">
                                                <span class="inline-flex items-center gap-1 rounded-full bg-emerald-500/10 px-2 py-0.5 font-medium text-emerald-400">✓ Terisi</span>
                                                <span class="text-slate-500">nilai tidak ditampilkan</span>
                                            </span>
                                            <label class="flex items-center gap-1.5 text-slate-500 hover:text-red-400 cursor-pointer">
                                                <input type="checkbox" name="clear_settings[{{ $key }}]" value="1" class="rounded border-slate-600 bg-slate-800 text-primary">
                                                Hapus
                                            </label>
                                        </div>
                                    @else
                                        <p class="text-[11px] text-amber-400/90">⚠️ Belum ada nilai tersimpan di database</p>
                                    @endif
                                    <p class="text-[11px] text-slate-500">Kosongkan jika tidak ingin mengubah nilai yang sudah tersimpan</p>
                                </div>
                            @elseif ($key === 'tripay_mode')
                                <select name="settings[{{ $key }}]" class="input-dark font-mono text-xs">
                                    @foreach (['sandbox' => 'Sandbox (uji)', 'live' => 'Live (uang nyata)'] as $modeValue => $modeLabel)
                                        <option value="{{ $modeValue }}" @selected(strtolower(trim((string) old('settings.'.$key, $settings[$key] ?? $tripayMode))) === $modeValue)>{{ $modeLabel }}</option>
                                    @endforeach
                                </select>
                                <p class="mt-1 text-[11px] text-slate-500">Sandbox memakai api-sandbox, Live memakai api. Base URL diturunkan dari mode dan tidak bisa diubah dari form.</p>
                            @else
                                <input
                                    type="text"
                                    name="settings[{{ $key }}]"
                                    value="{{ old('settings.'.$key, $settings[$key] ?? '') }}"
                                    class="input-dark font-mono text-xs"
                                    autocomplete="off"
                                >
                            @endif
                        </div>
                    @endforeach
                </div>
            </section>
        @endforeach

        @php
            // --- Ringkasan kesiapan Tripay -------------------------------------
            // Kredensial Tripay diatur dari panel ini (bukan .env). Nilai
            // secret TIDAK pernah dirender balik: hanya status terisi.
            $tripayLabels = [
                'tripay_mode' => $schema['tripay_mode'] ?? 'Tripay Mode (sandbox / live)',
                'tripay_api_key' => $schema['tripay_api_key'] ?? 'Tripay API Key',
                'tripay_private_key' => $schema['tripay_private_key'] ?? 'Tripay Private Key',
                'tripay_merchant_code' => $schema['tripay_merchant_code'] ?? 'Tripay Merchant Code',
            ];

            $tripayModeStored = strtolower(trim((string) ($settings['tripay_mode'] ?? '')));
            $tripayModeValid = in_array($tripayModeStored, ['sandbox', 'live'], true);
            $tripayModeShown = $tripayModeValid ? $tripayModeStored : 'sandbox';

            $tripayCredentialKeys = ['tripay_api_key', 'tripay_private_key', 'tripay_merchant_code'];
            $tripayMissing = array_values(array_filter(
                $tripayCredentialKeys,
                fn ($key) => blank($settings[$key] ?? null)
            ));
            $tripayCredentialsReady = $tripayMissing === [];

            // Callback URL adalah rujukan operasional (satu URL per merchant di
            // dashboard Tripay), bukan input: menampilkannya read-only mencegah
            // admin "memperbaiki" host menjadi next.captshop.id yang tidak
            // memiliki route webhook.
            $tripayCallbackUrl = (string) config('payment.tripay.callback_url', 'https://captshop.id/webhook/tripay');
            $tripayCallbackHost = strtolower((string) (parse_url($tripayCallbackUrl, PHP_URL_HOST) ?: ''));
            $tripayCallbackHostOk = $tripayCallbackHost === 'captshop.id';

            $activeGateway = \App\Support\PaymentGateway::effective();
            $tripayActiveChannels = \App\Models\PaymentChannel::query()
                ->where('gateway', \App\Support\PaymentGateway::TRIPAY)
                ->where('is_active', true)
                ->count();

            // Catatan: saklar gateway TIDAK dirender di form ini. Satu-satunya
            // kontrol adalah form terpisah di bawah (route
            // admin.settings.payment-gateway) yang memeriksa readiness dan
            // menulis audit log. Menaruh select `settings[payment_gateway]`
            // di sini akan menjadi saklar kedua yang melewati guard itu.

            $tripayChecks = [
                ['ok' => $tripayModeValid, 'label' => $tripayModeValid ? 'Mode valid: '.$tripayModeShown : 'Mode belum diatur — sistem memakai sandbox'],
                ['ok' => filled($settings['tripay_api_key'] ?? null), 'label' => 'API Key terisi'],
                ['ok' => filled($settings['tripay_private_key'] ?? null), 'label' => 'Private Key terisi'],
                ['ok' => filled($settings['tripay_merchant_code'] ?? null), 'label' => 'Merchant Code terisi'],
                ['ok' => $tripayCallbackHostOk, 'label' => 'Callback host captshop.id'],
                ['ok' => $tripayActiveChannels > 0, 'label' => 'Channel Tripay aktif ('.$tripayActiveChannels.')'],
            ];
            $tripayFullyReady = $tripayCredentialsReady && $tripayCallbackHostOk && $tripayActiveChannels > 0;
        @endphp

        <section class="rounded-xl border border-primary/30 bg-primary/5 p-5" aria-labelledby="tripay-settings-heading">
            <div class="mb-4 flex flex-wrap items-center justify-between gap-2">
                <h2 id="tripay-settings-heading" class="font-heading text-sm font-bold text-slate-200">💳 Payment Gateway (Tripay)</h2>
                <div class="flex flex-wrap items-center gap-2 text-[11px]">
                    <span class="rounded-full border border-slate-700 bg-slate-900/60 px-2 py-0.5 text-slate-300">
                        Aktif untuk pesanan baru:
                        <span class="font-semibold {{ $activeGateway === 'tripay' ? 'text-emerald-400' : 'text-sky-300' }}">{{ ucfirst($activeGateway) }}</span>
                    </span>
                    <span class="rounded-full px-2 py-0.5 font-medium {{ $tripayFullyReady ? 'bg-emerald-500/10 text-emerald-400' : 'bg-amber-500/10 text-amber-300' }}">
                        {{ $tripayFullyReady ? '✓ Siap diaktifkan' : '⚠ Belum lengkap' }}
                    </span>
                </div>
            </div>

            <p class="mb-4 text-[11px] text-slate-500">
                Kredensial diatur dari panel ini dan dibaca langsung oleh server — tidak perlu mengubah
                <code class="rounded bg-slate-900/70 px-1 py-0.5 font-mono">.env</code>.
                Kolom secret yang dikosongkan berarti <span class="text-slate-300">nilai tersimpan dipertahankan</span>;
                centang <span class="text-slate-300">Hapus</span> untuk membuang nilai tersimpan — Tripay lalu berstatus belum siap sampai kredensial diisi ulang (tidak ada fallback <code class="rounded bg-slate-900/70 px-1 py-0.5 font-mono">.env</code>).
            </p>

            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                {{-- Mode: sandbox / live --}}
                <div>
                    <label for="tripay_mode" class="mb-1.5 block text-sm font-medium text-slate-300">{{ $tripayLabels['tripay_mode'] }}</label>
                    <select id="tripay_mode" name="settings[tripay_mode]" class="input-dark font-mono text-xs">
                        <option value="" @selected(! $tripayModeValid)>— Gunakan default (sandbox) —</option>
                        <option value="sandbox" @selected($tripayModeValid && $tripayModeStored === 'sandbox')>Sandbox — api-sandbox.tripay.co.id</option>
                        <option value="live" @selected($tripayModeValid && $tripayModeStored === 'live')>Live — api.tripay.co.id</option>
                    </select>
                    @if (! $tripayModeValid && $tripayModeStored !== '')
                        <p class="mt-1 text-[11px] text-amber-400/90">⚠️ Mode tersimpan tidak dikenali dan diabaikan; sistem memakai sandbox sampai disimpan ulang.</p>
                    @else
                        <p class="mt-1 text-[11px] text-slate-500">Mode menentukan origin API Tripay. Hanya sandbox dan live yang diterima; nilai lain ditolak.</p>
                    @endif
                    @error('settings.tripay_mode')<p class="mt-1.5 text-xs text-red-400">{{ $message }}</p>@enderror
                </div>

                {{-- Merchant code: bukan secret, ditampilkan agar mudah dicocokkan --}}
                <div>
                    <label for="tripay_merchant_code" class="mb-1.5 block text-sm font-medium text-slate-300">{{ $tripayLabels['tripay_merchant_code'] }}</label>
                    <input
                        id="tripay_merchant_code"
                        type="text"
                        name="settings[tripay_merchant_code]"
                        value="{{ old('settings.tripay_merchant_code', $settings['tripay_merchant_code'] ?? '') }}"
                        class="input-dark font-mono text-xs"
                        autocomplete="off"
                        placeholder="Contoh: T0001"
                    >
                    <p class="mt-1 text-[11px] text-slate-500">Kode merchant dari dashboard Tripay; dipakai pada signature transaksi.</p>
                    @error('settings.tripay_merchant_code')<p class="mt-1.5 text-xs text-red-400">{{ $message }}</p>@enderror
                </div>

                {{-- API Key & Private Key: secret, blank = pertahankan --}}
                @foreach (['tripay_api_key', 'tripay_private_key'] as $key)
                    <div>
                        <label for="{{ $key }}" class="mb-1.5 block text-sm font-medium text-slate-300">{{ $tripayLabels[$key] }}</label>
                        <div x-data="{ show: false }" class="space-y-1">
                            <div class="relative">
                                <input
                                    id="{{ $key }}"
                                    :type="show ? 'text' : 'password'"
                                    name="settings[{{ $key }}]"
                                    class="input-dark pr-10 font-mono text-xs"
                                    autocomplete="off"
                                    placeholder="{{ filled($settings[$key] ?? null) ? 'Terisi — kosongkan untuk mempertahankan nilai' : 'Masukkan ' . $tripayLabels[$key] }}"
                                >
                                <button
                                    type="button"
                                    @click="show = !show"
                                    class="absolute inset-y-0 right-0 flex items-center pr-3 text-slate-400 hover:text-slate-200"
                                    tabindex="-1"
                                    title="Lihat / Sembunyikan"
                                >
                                    <span x-show="!show" class="text-xs">👁️</span>
                                    <span x-show="show" class="text-xs" x-cloak>🙈</span>
                                </button>
                            </div>
                            @if (filled($settings[$key] ?? null))
                                <div class="flex items-center justify-between text-[11px] text-slate-400">
                                    <span class="flex items-center gap-1.5">
                                        <span class="inline-flex items-center gap-1 rounded-full bg-emerald-500/10 px-2 py-0.5 font-medium text-emerald-400">✓ Terisi</span>
                                        <span class="text-slate-500">nilai tidak ditampilkan</span>
                                    </span>
                                    <label class="flex items-center gap-1.5 text-slate-500 hover:text-red-400 cursor-pointer">
                                        <input type="checkbox" name="clear_settings[{{ $key }}]" value="1" class="rounded border-slate-600 bg-slate-800 text-primary">
                                        Hapus
                                    </label>
                                </div>
                            @else
                                <p class="text-[11px] text-amber-400/90">⚠️ Belum ada nilai tersimpan di database</p>
                            @endif
                            @error('settings.'.$key)<p class="mt-1.5 text-xs text-red-400">{{ $message }}</p>@enderror
                        </div>
                    </div>
                @endforeach

                {{-- Callback URL: read-only, tanpa name agar tidak pernah ikut tersimpan --}}
                <div class="sm:col-span-2">
                    <label for="tripay_callback_url" class="mb-1.5 block text-sm font-medium text-slate-300">Callback URL (read-only)</label>
                    <div x-data="{ copied: false }" class="flex flex-col gap-2 sm:flex-row">
                        <input
                            id="tripay_callback_url"
                            x-ref="callback"
                            type="text"
                            value="{{ $tripayCallbackUrl }}"
                            class="input-dark w-full font-mono text-xs text-slate-400"
                            readonly
                            aria-readonly="true"
                            tabindex="0"
                            onfocus="this.select()"
                        >
                        <button
                            type="button"
                            @click="navigator.clipboard.writeText($refs.callback.value); copied = true; setTimeout(() => copied = false, 1500)"
                            class="shrink-0 rounded-lg border border-slate-700 px-3 py-2 text-xs font-medium text-slate-300 hover:border-slate-500 hover:text-slate-100"
                        >
                            <span x-show="!copied">Salin</span>
                            <span x-show="copied" x-cloak>Tersalin ✓</span>
                        </button>
                    </div>
                    @if ($tripayCallbackHostOk)
                        <p class="mt-1 text-[11px] text-slate-500">
                            Daftarkan tepat URL ini di dashboard Tripay (Closed Payment — satu URL per merchant).
                            Host harus <span class="font-mono text-slate-300">captshop.id</span>, bukan
                            <span class="font-mono text-slate-500">next.captshop.id</span>.
                        </p>
                    @else
                        <p class="mt-1 text-[11px] text-red-400">
                            ⚠️ Host callback bukan captshop.id (terbaca: {{ $tripayCallbackHost !== '' ? $tripayCallbackHost : 'tidak valid' }}).
                            Perbaiki <code class="font-mono">config/payment.php</code> — callback tidak boleh diarahkan ke next.captshop.id.
                        </p>
                    @endif
                </div>
            </div>

            {{-- Kesiapan perpindahan gateway: fail-closed, hanya informasi --}}
            <div class="mt-5 rounded-lg border border-slate-800 bg-slate-900/50 p-4">
                <h3 class="mb-2 text-xs font-semibold uppercase tracking-wide text-slate-400">Kesiapan pindah ke Tripay</h3>
                <ul class="grid grid-cols-1 gap-1.5 text-[11px] sm:grid-cols-2">
                    @foreach ($tripayChecks as $check)
                        <li class="flex items-center gap-1.5 {{ $check['ok'] ? 'text-emerald-400' : 'text-amber-300' }}">
                            <span aria-hidden="true">{{ $check['ok'] ? '✓' : '○' }}</span>
                            <span>{{ $check['label'] }}</span>
                        </li>
                    @endforeach
                </ul>
                <p class="mt-3 text-[11px] text-slate-500">
                    Fail-closed: selama gateway tidak diaktifkan secara eksplisit, pesanan baru tetap memakai
                    <span class="text-slate-300">Belibayar</span> dan katalog Tripay tidak ditampilkan ke pembeli.
                    Mengaktifkan Tripay tanpa kredensial lengkap tidak akan membuat transaksi berjalan.
                    Saklar gateway ada pada panel <span class="text-slate-300">Gateway Pembayaran Aktif</span> di bawah.
                </p>
            </div>
        </section>

        <div class="flex gap-2">
            <button type="submit" class="btn-primary">💾 Simpan Pengaturan</button>
        </div>
    </form>

    {{-- Saklar gateway sengaja form terpisah: aksi eksplisit, teraudit, dan
         hanya berhasil bila Tripay benar-benar siap. --}}
    <section class="rounded-xl border border-slate-700 bg-slate-800/40 p-5">
        <h2 class="mb-1 font-heading text-sm font-bold text-slate-200">🔀 Gateway Pembayaran Aktif</h2>
        <p class="mb-4 text-[11px] text-slate-500">Hanya memengaruhi pesanan BARU. Transaksi yang sudah berjalan tetap diselesaikan gateway yang mencatatnya.</p>

        <div class="mb-3 flex flex-wrap items-center gap-2 text-sm">
            <span class="rounded-md border border-emerald-500/40 bg-emerald-500/10 px-2 py-1 font-mono text-xs text-emerald-300">
                Aktif: {{ $paymentGateway === 'tripay' ? 'Tripay' : 'Belibayar' }}
            </span>
            <span class="text-[11px] text-slate-500">
                Saklar tersimpan: {{ $paymentGatewayStored ?? 'belum diatur (default Belibayar)' }}
            </span>
        </div>

        @if ($paymentGatewayStored === 'tripay' && $paymentGateway !== 'tripay')
            <p class="mb-3 rounded-lg border border-amber-500/40 bg-amber-500/10 px-3 py-2 text-[11px] text-amber-300">
                ⚠️ Saklar tersimpan <span class="font-mono">tripay</span>, tetapi Tripay belum siap. Pesanan baru otomatis memakai Belibayar sampai kelengkapan di bawah terpenuhi.
            </p>
        @endif

        <dl class="mb-4 grid grid-cols-1 gap-2 text-[11px] text-slate-400 sm:grid-cols-2">
            <div>
                <dt class="text-slate-500">Mode Tripay</dt>
                <dd class="font-mono text-slate-300">{{ $tripayMode }}</dd>
            </div>
            <div class="min-w-0">
                <dt class="text-slate-500">Callback URL (daftarkan di dashboard Tripay)</dt>
                <dd class="truncate font-mono text-slate-300" title="{{ $tripayCallbackUrl }}">{{ $tripayCallbackUrl }}</dd>
            </div>
        </dl>

        @if ($tripayReadiness['ready'])
            <p class="mb-4 text-[11px] text-emerald-400">✅ Kredensial, channel aktif, dan callback URL siap — Tripay boleh diaktifkan.</p>
        @else
            <ul class="mb-4 list-disc space-y-1 pl-5 text-[11px] text-amber-400/90">
                @foreach ($tripayReadiness['missing'] as $missing)
                    <li>{{ $missing }}</li>
                @endforeach
            </ul>
        @endif

        <form action="{{ route('admin.settings.payment-gateway') }}" method="POST" class="flex flex-wrap items-end gap-3">
            @csrf
            <div>
                <label class="mb-1.5 block text-sm font-medium text-slate-300">Gateway untuk pesanan baru</label>
                <select name="gateway" class="input-dark font-mono text-xs">
                    <option value="belibayar" @selected($paymentGateway === 'belibayar')>Belibayar</option>
                    <option value="tripay" @selected($paymentGateway === 'tripay') {{ $tripayReadiness['ready'] ? '' : 'disabled' }}>Tripay{{ $tripayReadiness['ready'] ? '' : ' (belum siap)' }}</option>
                </select>
            </div>
            <button type="submit" class="btn-primary">Terapkan Gateway</button>
        </form>

        @error('gateway')
            <p class="mt-2 text-xs text-red-400">{{ $message }}</p>
        @enderror
        <p class="mt-2 text-[11px] text-slate-500">Rollback: pilih Belibayar lalu Terapkan. Tidak perlu mengubah .env atau config.</p>
    </section>

    {{-- Status saja: endpoint gateway dipanggil server-side oleh controller.
         Tidak ada aksi pairing, reset, logout, atau kirim pesan dari panel. --}}
    <section class="rounded-xl border border-slate-700 bg-slate-800/40 p-5" aria-labelledby="whatsapp-gateway-heading">
        <div class="mb-2 flex flex-wrap items-center justify-between gap-2">
            <h2 id="whatsapp-gateway-heading" class="font-heading text-sm font-bold text-slate-200">📱 WhatsApp Gateway</h2>
            <span class="rounded-full px-2 py-0.5 text-[11px] font-medium {{ $whatsappGatewayStatus['state'] === 'connected' ? 'bg-emerald-500/10 text-emerald-400' : 'bg-amber-500/10 text-amber-300' }}">
                {{ $whatsappGatewayStatus['state'] === 'connected' ? 'Terhubung' : ($whatsappGatewayStatus['state'] === 'disconnected' ? 'Terputus' : 'Tidak tersedia') }}
            </span>
        </div>
        <p class="text-[11px] text-slate-500">{{ $whatsappGatewayStatus['detail'] }}</p>
        <dl class="mt-4 grid grid-cols-1 gap-3 text-[11px] text-slate-400 sm:grid-cols-2">
            <div><dt class="text-slate-500">Pengirim</dt><dd class="font-mono text-slate-300">{{ $whatsappGatewayStatus['sender'] ?? '—' }}</dd></div>
            <div><dt class="text-slate-500">Terakhir terhubung</dt><dd class="font-mono text-slate-300">{{ $whatsappGatewayStatus['last_connected_at'] ?? '—' }}</dd></div>
            <div><dt class="text-slate-500">Terakhir terputus</dt><dd class="font-mono text-slate-300">{{ $whatsappGatewayStatus['last_disconnected_at'] ?? '—' }}</dd></div>
            <div><dt class="text-slate-500">Antrean</dt><dd class="text-slate-300">{{ $whatsappGatewayStatus['queue']['queued'] ?? 0 }} queued · {{ $whatsappGatewayStatus['queue']['retryable'] ?? 0 }} retryable · {{ $whatsappGatewayStatus['queue']['failed'] ?? 0 }} gagal</dd></div>
        </dl>
        <p class="mt-2 text-[11px] text-slate-600">Panel ini hanya menampilkan status read-only. Pengelolaan sesi dilakukan pada host gateway.</p>
    </section>
@endsection
