@extends('layouts.app')

@section('title', $siteName ?? 'CaptShop — Top-Up Game & PPOB')

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
                <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-6">
                    @foreach ($popularProducts as $product)
                        <x-product_card :product="$product" />
                    @endforeach
                </div>
            @else
                <p class="rounded-xl border border-dashed border-slate-700 p-6 text-center text-sm text-slate-500">
                    Belum ada produk populer. Nantikan segera! 🎮
                </p>
            @endif
        </section>

        <x-trust-section :site-name="$siteName ?? null" />
    </div>
@endsection
