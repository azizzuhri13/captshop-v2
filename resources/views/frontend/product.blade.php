@extends('layouts.app')

@section('title', $product->name.' — Top Up Termurah | '.($siteName ?? 'CAPTSHOP'))
@section('meta_description', 'Top up '.$product->name.' cepat, aman, dan murah di '.($siteName ?? 'CAPTSHOP').'. Proses instan 24/7, pembayaran lengkap, tanpa perlu login.')

@php
    $siteName = $siteName ?? (\App\Models\SiteSetting::get('site_name') ?? 'CAPTSHOP');

    // Config untuk Alpine orderForm (harga effective: flash sale bila aktif).
    $alpineItems = $product->items->map(fn ($item) => [
        'id' => $item->id,
        'name' => $item->name,
        'group' => $item->group,
        'price' => (float) $item->effectivePrice(),
        'original_price' => (float) $item->sell_price,
        'flash_sale' => $item->isFlashSaleActive(),
    ])->values();

    $alpineFields = collect($product->input_fields ?? [])->map(fn ($field) => [
        'name' => $field['name'] ?? '',
        'label' => $field['label'] ?? ucfirst($field['name'] ?? ''),
        'type' => $field['type'] ?? 'text',
        'required' => (bool) ($field['required'] ?? false),
        'placeholder' => $field['placeholder'] ?? '',
    ])->values();

    $alpineChannels = $paymentChannels->flatten()->map(fn ($channel) => [
        'code' => $channel->code,
        'name' => $channel->name,
        'group' => $channel->group,
        'flat_fee' => (float) $channel->flat_fee,
        'percent_fee' => (float) $channel->percent_fee,
    ])->values();

    // SEO: Open Graph & Twitter Card.
    $ogTitle = $product->name.' — '.($siteName ?? 'CAPTSHOP');
    $ogDescription = 'Top up '.$product->name.' cepat, aman, dan murah. Proses instan 24/7 di '.($siteName ?? 'CAPTSHOP').'.';
    $ogImage = $product->bannerUrl() ?? $product->iconUrl();
    $productUrl = route('product.show', [$product->category->slug, $product->slug]);

    // SEO: Schema.org Product & BreadcrumbList (JSON-LD).
    $jsonLd = [
        '@context' => 'https://schema.org',
        '@type' => 'Product',
        'name' => $product->name,
        'description' => $product->description ?? $ogDescription,
        'image' => $ogImage,
        'url' => $productUrl,
        'brand' => ['@type' => 'Brand', 'name' => $siteName ?? 'CAPTSHOP'],
        'offers' => [
            '@type' => 'AggregateOffer',
            'priceCurrency' => 'IDR',
            'lowPrice' => (float) $product->items->min(fn ($item) => (float) $item->effectivePrice()),
            'highPrice' => (float) $product->items->max(fn ($item) => (float) $item->effectivePrice()),
            'offerCount' => $product->items->count(),
            'availability' => 'https://schema.org/InStock',
        ],
        'aggregateRating' => [
            '@type' => 'AggregateRating',
            'ratingValue' => '4.9',
            'reviewCount' => '850',
            'bestRating' => '5',
            'worstRating' => '1',
        ],
    ];

    $breadcrumbLd = [
        '@context' => 'https://schema.org',
        '@type' => 'BreadcrumbList',
        'itemListElement' => [
            ['@type' => 'ListItem', 'position' => 1, 'name' => 'Home', 'item' => route('home')],
            ['@type' => 'ListItem', 'position' => 2, 'name' => $product->category->name, 'item' => route('category.show', $product->category->slug)],
            ['@type' => 'ListItem', 'position' => 3, 'name' => $product->name, 'item' => $productUrl],
        ],
    ];
@endphp

@section('og', json_encode([
    'title' => $ogTitle,
    'description' => $ogDescription,
    'image' => $ogImage,
    'url' => $productUrl,
    'type' => 'product',
]))

@push('head')
    <script type="application/ld+json">{!! json_encode($jsonLd, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) !!}</script>
    <script type="application/ld+json">{!! json_encode($breadcrumbLd, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) !!}</script>
@endpush

@section('content')
    <div x-data="orderForm({
            fields: {{ Js::from($alpineFields) }},
            items: {{ Js::from($alpineItems) }},
            channels: {{ Js::from($alpineChannels) }},
            couponCheckUrl: '{{ route('coupon.check') }}',
            gameSlug: '{{ $product->slug }}',
            checkNicknameUrl: '{{ route('game.check-nickname') }}',
            isGameAccount: {{ app(\App\Services\GameValidator\GameValidatorInterface::class)->isSupported($product->slug) ? 'true' : 'false' }},
            itemId: {{ Js::from(old('product_item_id')) }},
            paymentChannel: {{ Js::from(old('payment_channel')) }},
            customerData: {{ Js::from(old('customer_data', [])) }},
            customer_phone: {{ Js::from(old('customer_phone', '')) }},
            customer_email: {{ Js::from(old('customer_email', '')) }},
            couponCode: {{ Js::from(old('coupon_code', '')) }},
        })"
         class="space-y-6"
    >
        {{-- Header produk --}}
        <header class="flex items-center gap-4">
            <div class="grid h-16 w-16 shrink-0 place-items-center overflow-hidden rounded-2xl border border-slate-800 bg-slate-800">
                @if ($product->iconUrl())
                    <img src="{{ $product->iconUrl() }}" alt="{{ $product->name }}" class="h-full w-full object-cover">
                @else
                    <span class="font-heading text-xl font-bold text-slate-600">{{ strtoupper(substr($product->name, 0, 2)) }}</span>
                @endif
            </div>
            <div>
                <nav class="mb-1 flex items-center gap-2 text-xs text-slate-500" aria-label="Breadcrumb">
                    <a href="{{ route('home') }}" class="hover:text-slate-300">Home</a>
                    <span>/</span>
                    <a href="{{ route('category.show', $product->category->slug) }}" class="hover:text-slate-300">{{ $product->category->name }}</a>
                </nav>
                <h1 class="font-heading text-xl font-bold text-slate-100">{{ $product->name }}</h1>
            </div>
        </header>

        @if ($product->description)
            <p class="rounded-xl border border-slate-800 bg-slate-800/40 p-4 text-sm leading-relaxed text-slate-400">{{ $product->description }}</p>
        @endif

        <form action="{{ route('order.store') }}" method="POST" class="space-y-6" @submit="processing = true">
            @csrf
            <input type="hidden" name="product_item_id" :value="itemId">

            {{-- STEP 1: Masukkan Data --}}
            <section class="rounded-2xl border border-slate-800 bg-slate-800/40 p-4" aria-label="Langkah 1: Masukkan data">
                <h2 class="mb-3 flex items-center gap-2 font-heading text-sm font-bold text-slate-100">
                    <span class="grid h-6 w-6 place-items-center rounded-full bg-primary text-xs font-bold">1</span>
                    Masukkan Data
                </h2>

                <div class="space-y-3">
                    <template x-for="(field, index) in fields" :key="index">
                        <div>
                            <label class="mb-1.5 block text-sm font-medium text-slate-300" :for="'field-' + field.name">
                                <span x-text="field.label"></span>
                                <span x-show="field.required" class="text-primary-light">*</span>
                            </label>
                            <input
                                :type="field.type"
                                :id="'field-' + field.name"
                                :name="'customer_data[' + field.name + ']'"
                                :placeholder="field.placeholder"
                                :required="field.required"
                                x-model="customerData[field.name]"
                                class="input-dark"
                            >
                        </div>
                    </template>
                </div>

                {{-- Status Verifikasi Nickname Game --}}
                <template x-if="isGameAccount">
                    <div class="mt-3">
                        <div x-show="nicknameChecking" x-cloak class="flex items-center gap-2 rounded-xl border border-sky-500/30 bg-sky-500/10 p-3 text-xs text-sky-300">
                            <svg class="h-4 w-4 animate-spin shrink-0" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8H4z"></path></svg>
                            <span>Memeriksa User ID ke server game...</span>
                        </div>

                        <div x-show="nickname && !nicknameChecking" x-cloak class="flex items-center justify-between rounded-xl border border-emerald-500/40 bg-emerald-500/15 p-3 text-xs text-emerald-300">
                            <div class="flex items-center gap-2">
                                <span class="grid h-5 w-5 place-items-center rounded-full bg-emerald-500/30 text-emerald-200">✓</span>
                                <div>
                                    <span class="text-slate-400">Nickname:</span>
                                    <strong class="ml-1 text-sm font-bold text-emerald-200" x-text="nickname"></strong>
                                </div>
                            </div>
                            <span class="rounded bg-emerald-500/20 px-2 py-0.5 text-[10px] font-semibold text-emerald-300">Akun Ditemukan</span>
                        </div>

                        <div x-show="nicknameError && !nicknameChecking" x-cloak class="flex items-center gap-2 rounded-xl border border-red-500/40 bg-red-500/15 p-3 text-xs text-red-300">
                            <span class="grid h-5 w-5 place-items-center rounded-full bg-red-500/30 text-red-200">✕</span>
                            <span x-text="nicknameError"></span>
                        </div>
                    </div>
                </template>

                @if ($product->instruction)
                    <details class="mt-3 text-sm text-slate-400">
                        <summary class="cursor-pointer font-medium text-primary-light">Cara pesan {{ $product->name }}?</summary>
                        <p class="mt-2 whitespace-pre-line leading-relaxed">{{ $product->instruction }}</p>
                    </details>
                @endif
            </section>

            {{-- STEP 2: Pilih Nominal --}}
            <section class="rounded-2xl border border-slate-800 bg-slate-800/40 p-4" aria-label="Langkah 2: Pilih nominal">
                <h2 class="mb-3 flex items-center gap-2 font-heading text-sm font-bold text-slate-100">
                    <span class="grid h-6 w-6 place-items-center rounded-full bg-primary text-xs font-bold">2</span>
                    Pilih Nominal
                </h2>

                <div class="space-y-4">
                    <template x-for="(grp, gi) in itemGroups" :key="gi">
                        <div>
                            <h3
                                x-show="grp.name !== ''"
                                class="mb-2 flex items-center gap-1.5 font-heading text-sm font-semibold text-slate-300"
                            >
                                <span x-text="grp.name"></span>
                                <svg class="h-3.5 w-3.5 text-primary shrink-0" fill="currentColor" viewBox="0 0 20 20" aria-hidden="true">
                                    <path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.54 1.118l-2.8-2.034a1 1 0 00-1.175 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z" />
                                </svg>
                            </h3>

                            <div class="grid grid-cols-2 gap-2 sm:grid-cols-3">
                                <template x-for="item in grp.items" :key="item.id">
                                    <button
                                        type="button"
                                        @click="itemId = item.id"
                                        :class="itemId === item.id ? 'border-primary bg-primary/15' : 'border-slate-700 bg-slate-800 hover:border-slate-600'"
                                        class="relative flex flex-col items-center gap-0.5 rounded-xl border px-3 py-3 text-center transition"
                                    >
                                        <span class="text-sm font-semibold text-slate-100" x-text="item.name"></span>
                                        <span class="font-heading text-sm font-bold" :class="item.flash_sale ? 'text-accent' : 'text-secondary'" x-text="formatRupiah(item.price)"></span>
                                        <span x-show="item.flash_sale" class="text-[11px] text-slate-500 line-through" x-text="formatRupiah(item.original_price)"></span>
                                    </button>
                                </template>
                            </div>
                        </div>
                    </template>
                </div>
            </section>

            {{-- STEP 3: Pilih Pembayaran --}}
            <section class="rounded-2xl border border-slate-800 bg-slate-800/40 p-4" aria-label="Langkah 3: Pilih pembayaran">
                <h2 class="mb-3 flex items-center gap-2 font-heading text-sm font-bold text-slate-100">
                    <span class="grid h-6 w-6 place-items-center rounded-full bg-primary text-xs font-bold">3</span>
                    Pilih Pembayaran
                </h2>

                <x-payment-selector :channels="$paymentChannels" />
            </section>

            {{-- STEP 4: Kontak --}}
            <section class="rounded-2xl border border-slate-800 bg-slate-800/40 p-4" aria-label="Langkah 4: Kontak">
                <h2 class="mb-3 flex items-center gap-2 font-heading text-sm font-bold text-slate-100">
                    <span class="grid h-6 w-6 place-items-center rounded-full bg-primary text-xs font-bold">4</span>
                    Kontak
                </h2>

                <div class="space-y-3">
                    <div>
                        <label for="customer_phone" class="mb-1.5 block text-sm font-medium text-slate-300">
                            No. WhatsApp <span class="text-primary-light">*</span>
                        </label>
                        <input
                            type="tel"
                            id="customer_phone"
                            name="customer_phone"
                            placeholder="08xxxxxxxxxx"
                            x-model="form.customer_phone"
                            required
                            class="input-dark"
                        >
                    </div>

                    <div>
                        <label for="customer_email" class="mb-1.5 block text-sm font-medium text-slate-300">Email {{ config('payment.default') === 'belibayar' ? '*' : '(opsional)' }}</label>
                        <input
                            type="email"
                            id="customer_email"
                            name="customer_email"
                            @if (config('payment.default') === 'belibayar') required @endif
                            placeholder="nama@email.com"
                            x-model="form.customer_email"
                            class="input-dark"
                        >
                    </div>

                    {{-- Kupon diskon --}}
                    <div>
                        <label for="coupon_code" class="mb-1.5 block text-sm font-medium text-slate-300">Kode Kupon</label>

                        <template x-if="! coupon">
                            <div class="flex gap-2">
                                <input
                                    type="text"
                                    id="coupon_code"
                                    x-model="couponCode"
                                    placeholder="Masukkan kode kupon"
                                    class="input-dark flex-1 uppercase"
                                    :class="couponError && 'border-red-500/60'"
                                    @keydown.enter.prevent="applyCoupon"
                                >
                                <button
                                    type="button"
                                    class="btn-primary shrink-0 px-4 py-2.5 text-sm"
                                    :disabled="couponChecking || ! couponCode || ! selectedItem"
                                    @click="applyCoupon"
                                    x-text="couponChecking ? 'Memeriksa...' : 'Pakai'"
                                >
                                    Pakai
                                </button>
                            </div>
                        </template>

                        <template x-if="coupon">
                            <div class="flex items-center justify-between gap-2 rounded-lg border border-secondary/40 bg-secondary/10 px-4 py-2.5 text-sm">
                                <span class="flex items-center gap-2 text-emerald-300">
                                    <svg class="h-4 w-4 shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m6 2a9 9 0 1 1-18 0 9 9 0 0 1 18 0z"/></svg>
                                    <span>
                                        <span class="font-semibold" x-text="coupon.code"></span>
                                        — hemat <span class="font-semibold" x-text="coupon.discount_formatted"></span>
                                    </span>
                                </span>
                                <button type="button" class="shrink-0 text-emerald-400/60 hover:text-emerald-300" aria-label="Hapus kupon" @click="removeCoupon">✕</button>
                            </div>
                        </template>

                        <p x-show="couponError" x-cloak class="mt-1.5 text-xs text-red-400" x-text="couponError"></p>
                    </div>
                </div>
            </section>

            {{-- Ringkasan & Submit --}}
            <section class="sticky bottom-[calc(0.5rem_+_env(safe-area-inset-bottom))] z-30 rounded-2xl border border-slate-700 bg-slate-900/95 p-4 shadow-lg shadow-black/30 backdrop-blur lg:bottom-4" aria-label="Ringkasan pesanan">
                <div class="mb-3 space-y-1 text-sm">
                    <div class="flex items-center justify-between text-slate-400">
                        <span x-text="selectedItem ? selectedItem.name : 'Pilih nominal'"></span>
                        <span class="font-medium text-slate-200" x-text="formatRupiah(price)"></span>
                    </div>
                    <div x-show="coupon" x-cloak class="flex items-center justify-between text-emerald-400">
                        <span>Diskon <span class="font-semibold" x-text="coupon ? coupon.code : ''"></span></span>
                        <span class="font-medium">-<span x-text="formatRupiah(discount)"></span></span>
                    </div>
                    <div class="flex items-center justify-between text-slate-400">
                        <span>Biaya layanan</span>
                        <span class="font-medium text-slate-200" x-text="formatRupiah(fee)"></span>
                    </div>
                    <div class="flex items-center justify-between border-t border-slate-800 pt-2 font-heading text-base font-bold">
                        <span>Total</span>
                        <span class="text-secondary" x-text="formatRupiah(total)"></span>
                    </div>
                </div>

                <input type="hidden" name="coupon_code" :value="coupon ? coupon.code : ''">

                <p x-show="! canSubmit" x-cloak class="mb-2 text-center text-xs text-amber-400/90">
                    Pilih <span x-text="missingHint"></span> terlebih dahulu.
                </p>

                <button type="submit" class="btn-primary w-full text-base" :disabled="! canSubmit || processing" x-text="processing ? 'Memproses...' : '🛒 BELI SEKARANG'">
                    🛒 BELI SEKARANG
                </button>
            </section>
        </form>
    </div>
@endsection
