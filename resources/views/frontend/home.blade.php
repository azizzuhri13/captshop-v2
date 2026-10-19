@extends('layouts.app')

@section('title', $siteName ?? 'CAPTSHOP — Top-Up Game & PPOB')

@php
    $siteUrl = url('/');
    $homeStructuredData = [
        '@context' => 'https://schema.org',
        '@graph' => [
            [
                '@type' => 'Organization',
                '@id' => $siteUrl . '/#organization',
                'name' => $siteName ?? 'CAPTSHOP',
                'url' => $siteUrl,
                'logo' => [
                    '@type' => 'ImageObject',
                    'url' => asset('icon-512.png'),
                    'width' => 512,
                    'height' => 512,
                ],
            ],
            [
                '@type' => 'WebSite',
                '@id' => $siteUrl . '/#website',
                'url' => $siteUrl,
                'name' => $siteName ?? 'CAPTSHOP',
                'description' => 'Layanan top up game termurah, cepat, dan aman 24 jam di Indonesia.',
                'publisher' => [
                    '@id' => $siteUrl . '/#organization',
                ],
                'potentialAction' => [
                    '@type' => 'SearchAction',
                    'target' => [
                        '@type' => 'EntryPoint',
                        'urlTemplate' => $siteUrl . '/search?q={search_term_string}',
                    ],
                    'query-input' => 'required name=search_term_string',
                ],
            ],
            [
                '@type' => 'ItemList',
                '@id' => $siteUrl . '/#sitelinks',
                'name' => 'Top Up Game Populer',
                'itemListElement' => [
                    [
                        '@type' => 'ListItem',
                        'position' => 1,
                        'name' => 'Mobile Legends',
                        'url' => url('/topup/mobile-legends'),
                    ],
                    [
                        '@type' => 'ListItem',
                        'position' => 2,
                        'name' => 'Magic Chess Go Go',
                        'url' => url('/topup/magic-chess-go-go'),
                    ],
                    [
                        '@type' => 'ListItem',
                        'position' => 3,
                        'name' => 'Free Fire',
                        'url' => url('/topup/free-fire'),
                    ],
                    [
                        '@type' => 'ListItem',
                        'position' => 4,
                        'name' => 'PUBG Mobile',
                        'url' => url('/topup/pubg-mobile'),
                    ],
                    [
                        '@type' => 'ListItem',
                        'position' => 5,
                        'name' => 'Valorant',
                        'url' => url('/topup/valorant'),
                    ],
                    [
                        '@type' => 'ListItem',
                        'position' => 6,
                        'name' => 'Honor of Kings',
                        'url' => url('/topup/honor-of-kings'),
                    ],
                ],
            ],
        ],
    ];
@endphp

@push('head')
    <script type="application/ld+json">{!! json_encode($homeStructuredData, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) !!}</script>
@endpush

@section('content')
    <div class="space-y-8">
        <x-hero-carousel :banners="$banners" />

        <x-category-grid :categories="$categories" />

        @if ($premiumAppsProducts->isNotEmpty())
            <x-apps-premium-slider :products="$premiumAppsProducts" :category="$premiumAppsCategory" />
        @endif

        @if ($flashSaleItems->isNotEmpty())
            <x-flash-sale :items="$flashSaleItems" />
        @endif

        <section aria-label="Produk populer">
            <div class="mb-4 flex items-center justify-between">
                <h2 class="flex items-center gap-2 font-heading text-base font-bold text-slate-100">
                    <span>⭐</span> Produk Populer
                </h2>
                @if ($categories->isNotEmpty())
                    <a href="{{ route('category.show', $categories->first()->slug) }}" class="text-sm font-medium text-primary-light hover:text-white">Lihat semua →</a>
                @endif
            </div>

            @if ($popularProducts->isNotEmpty())
                <div class="grid grid-cols-2 gap-3 sm:grid-cols-4 lg:grid-cols-4">
                    @foreach ($popularProducts as $product)
                        <x-product_card :product="$product" />
                    @endforeach
                </div>
                <div class="mt-6 mb-4 text-center">
                    <a href="{{ route('category.show', 'topup') }}" class="inline-flex w-full items-center justify-center rounded-xl border border-slate-700 bg-slate-800/80 px-6 py-3 text-sm font-semibold text-slate-200 transition hover:border-primary hover:bg-slate-700 hover:text-white sm:w-auto">
                        Lihat Semua Game
                    </a>
                </div>
            @else
                <p class="rounded-xl border border-dashed border-slate-700 p-6 text-center text-sm text-slate-500">
                    Belum ada produk populer. Nantikan segera! 🎮
                </p>
            @endif
        </section>

        @if (isset($latestArticles) && $latestArticles->isNotEmpty())
            <section aria-label="Artikel dan Berita Terbaru" class="space-y-4">
                <div class="flex items-center justify-between">
                    <div>
                        <h2 class="font-heading text-base font-bold text-slate-100 flex items-center gap-2">
                            <span>📰</span> Kabar &amp; Tips Gamer
                        </h2>
                        <p class="text-xs text-slate-400">Update event resmi, tips hero, dan berita game terkini</p>
                    </div>
                    <a href="{{ route('articles.index') }}" class="text-xs font-semibold text-primary-light hover:text-white transition">
                        Lihat Semua →
                    </a>
                </div>

                <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                    @foreach ($latestArticles as $article)
                        <x-article-card :article="$article" />
                    @endforeach
                </div>
            </section>
        @endif

        <x-trust-section :site-name="$siteName ?? null" />
    </div>
@endsection
