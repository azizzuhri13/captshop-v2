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

        @foreach ([
            'Payment Gateway (Belibayar)' => ['belibayar_api_key', 'belibayar_secret_key', 'belibayar_webhook_secret', 'belibayar_merchant_id'],
            '🔌 Supplier Utama (Default)' => ['supplier_default'],
            '🔌 Supplier (DigiFlazz)' => ['digiflazz_username', 'digiflazz_api_key', 'digiflazz_base_url'],
            '🔌 Supplier (Tokovoucher / Apigames)' => ['tokovoucher_member_code', 'tokovoucher_secret_key', 'tokovoucher_signature', 'tokovoucher_base_url'],
            '💬 WhatsApp Gateway (Fonnte)' => ['fonnte_token', 'fonnte_base_url'],
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
                                            placeholder="{{ !empty($settings[$key]) ? 'Tersimpan (panjang ' . strlen($settings[$key]) . ' kar, akhiran ...' . substr($settings[$key], -4) . ')' : 'Masukkan ' . $schema[$key] }}"
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
                                    @if (!empty($settings[$key]))
                                        <div class="flex items-center justify-between text-[11px] text-slate-400">
                                            <span>Tersimpan: <span class="font-mono text-emerald-400">...{{ substr($settings[$key], -4) }}</span> ({{ strlen($settings[$key]) }} kar)</span>
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

        <div class="flex gap-2">
            <button type="submit" class="btn-primary">💾 Simpan Pengaturan</button>
        </div>
    </form>
@endsection
