@extends('layouts.app')

@section('title', $category->name.' — '.($siteName ?? 'CAPTSHOP'))

@section('content')
    <div class="space-y-6">
        {{-- Breadcrumb --}}
        <nav class="flex items-center gap-2 text-xs text-slate-500" aria-label="Breadcrumb">
            <a href="{{ route('home') }}" class="hover:text-slate-300">Home</a>
            <span>/</span>
            <span class="text-slate-300">{{ $category->name }}</span>
        </nav>

        <header class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <h1 class="font-heading text-xl font-bold text-slate-100">{{ $category->name }}</h1>
                <p class="mt-1 text-sm text-slate-500">{{ $products->total() }} produk tersedia</p>
            </div>

            <form method="GET" class="flex items-center gap-2">
                @if (request('q'))
                    <input type="hidden" name="q" value="{{ request('q') }}">
                @endif
                <select name="sort" onchange="this.form.submit()" class="rounded-lg border border-slate-700 bg-slate-800 px-3 py-2 text-sm text-slate-200 focus:border-primary focus:outline-none" aria-label="Urutkan">
                    <option value="popular" @selected(request('sort', 'popular') === 'popular')>Terpopuler</option>
                    <option value="newest" @selected(request('sort') === 'newest')>Terbaru</option>
                    <option value="name" @selected(request('sort') === 'name')>A-Z</option>
                </select>
            </form>
        </header>

        {{-- Sub-kategori --}}
        @if ($category->children->isNotEmpty())
            <div class="scrollbar-none -mx-4 flex gap-2 overflow-x-auto px-4">
                @foreach ($category->children as $child)
                    <a href="{{ route('category.show', $child->slug) }}" class="shrink-0 rounded-full border border-slate-700 bg-slate-800 px-4 py-1.5 text-xs font-medium text-slate-300 transition hover:border-primary hover:text-white">
                        {{ $child->name }}
                    </a>
                @endforeach
            </div>
        @endif

        {{-- Grid produk --}}
        @if ($products->isNotEmpty())
            <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-6">
                @foreach ($products as $product)
                    <x-product_card :product="$product" />
                @endforeach
            </div>

            <div class="mt-6">
                {{ $products->links() }}
            </div>
        @else
            <p class="rounded-xl border border-dashed border-slate-700 p-8 text-center text-sm text-slate-500">
                Belum ada produk di kategori ini. 🙁
            </p>
        @endif
    </div>
@endsection
