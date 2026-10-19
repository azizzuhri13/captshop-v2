@extends('layouts.app')

@section('title', $article->title . ' — CAPTSHOP')
@section('meta_description', $article->excerpt ?? Str::limit(strip_tags($article->body), 150))
@section('canonical', route('articles.show', $article->slug))

@php
    $ogImage = $article->thumbnailUrl() ?? asset('/icon-512.png');
    $currentUrl = route('articles.show', $article->slug);

    $jsonLd = [
        '@context' => 'https://schema.org',
        '@type' => 'BlogPosting',
        'mainEntityOfPage' => [
            '@type' => 'WebPage',
            '@id' => $currentUrl,
        ],
        'headline' => $article->title,
        'description' => $article->excerpt ?? Str::limit(strip_tags($article->body), 150),
        'image' => [$ogImage],
        'datePublished' => $article->published_at?->toISOString() ?? now()->toISOString(),
        'dateModified' => $article->updated_at?->toISOString() ?? now()->toISOString(),
        'author' => [
            '@type' => 'Person',
            'name' => $article->author?->name ?? 'Admin CAPTSHOP',
        ],
        'publisher' => [
            '@type' => 'Organization',
            'name' => 'CAPTSHOP',
            'logo' => [
                '@type' => 'ImageObject',
                'url' => asset('/icon-192.png'),
            ],
        ],
    ];
@endphp

@section('og', json_encode([
    'title' => $article->title,
    'description' => $article->excerpt ?? Str::limit(strip_tags($article->body), 150),
    'image' => $ogImage,
    'url' => $currentUrl,
    'type' => 'article',
]))

@push('head')
    <script type="application/ld+json">{!! json_encode($jsonLd, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) !!}</script>
@endpush

@section('content')
<div x-data="{
        progress: 0,
        copied: false,
        copyLink() {
            navigator.clipboard.writeText(window.location.href);
            this.copied = true;
            setTimeout(() => this.copied = false, 2500);
        }
    }"
    @scroll.window="progress = Math.min(100, Math.max(0, (window.scrollY / (document.documentElement.scrollHeight - window.innerHeight)) * 100))"
    class="relative mx-auto max-w-4xl"
>
    {{-- Neon EXP Reading Progress Bar --}}
    <div class="fixed top-0 left-0 z-50 h-1 bg-slate-800 w-full">
        <div class="h-full bg-gradient-to-r from-primary via-primary-light to-amber-500 transition-all duration-75" :style="`width: ${progress}%`"></div>
    </div>

    {{-- Breadcrumb --}}
    <nav class="mb-4 flex items-center gap-2 text-xs text-slate-500" aria-label="Breadcrumb">
        <a href="{{ route('home') }}" class="hover:text-slate-300">Home</a>
        <span>/</span>
        <a href="{{ route('articles.index') }}" class="hover:text-slate-300">Artikel</a>
        @if ($article->category)
            <span>/</span>
            <a href="{{ route('articles.index', ['kategori' => $article->category]) }}" class="hover:text-slate-300">{{ $article->category }}</a>
        @endif
    </nav>

    {{-- Article Header --}}
    <header class="mb-6 space-y-4">
        @if ($article->category)
            <span class="inline-block rounded-lg bg-primary/15 px-3 py-1 text-xs font-bold text-primary-light border border-primary/30">
                {{ $article->category }}
            </span>
        @endif

        <h1 class="font-heading text-2xl sm:text-4xl font-extrabold text-white leading-tight">
            {{ $article->title }}
        </h1>

        <div class="flex flex-wrap items-center gap-x-2 gap-y-1 text-[11px] sm:text-xs text-slate-400 border-b border-slate-800 pb-3">
            <span class="font-medium text-slate-200">{{ $article->author?->name ?? 'Super Admin' }}</span>
            <span class="text-slate-600">•</span>
            <time datetime="{{ $article->published_at?->toISOString() }}">
                {{ $article->published_at?->format('d M Y') }}
            </time>
            <span class="text-slate-600">•</span>
            <span>{{ $article->reading_time_minutes ?? 2 }} mnt baca</span>
            <span class="text-slate-600">•</span>
            <span class="text-slate-300 font-medium">{{ number_format($article->views_count) }} tayangan</span>
        </div>
    </header>

    {{-- Featured Thumbnail --}}
    @if ($article->thumbnailUrl())
        <div class="mb-8 aspect-video w-full overflow-hidden rounded-3xl border border-slate-800 bg-slate-900 shadow-2xl">
            <img src="{{ $article->thumbnailUrl() }}" alt="{{ $article->title }}" class="h-full w-full object-cover">
        </div>
    @endif

    {{-- Article Body --}}
    <div class="article-content">
        {!! $article->body !!}
    </div>

    {{-- Contextual In-Article Top-Up Card (Jika ada relasi game) --}}
    @if ($article->relatedProduct)
        <x-in-article-topup :product="$article->relatedProduct" />
    @endif

    {{-- Secret Loot Box Promo Drop --}}
    <div class="my-8 rounded-2xl border border-dashed border-amber-500/50 bg-gradient-to-r from-amber-500/10 via-slate-900 to-amber-500/10 p-5 text-center sm:text-left sm:flex sm:items-center sm:justify-between sm:gap-4">
        <div>
            <span class="text-xs font-bold text-amber-400 uppercase tracking-wider">🎁 Secret Loot Drop</span>
            <p class="text-sm font-bold text-white mt-1">Gunakan kupon promo untuk top-up game hari ini!</p>
            <p class="text-xs text-slate-400">Dapatkan potongan instan saat checkout produk di CAPTSHOP.</p>
        </div>
        <div class="mt-4 sm:mt-0 shrink-0">
            <a href="{{ route('home') }}" class="btn-primary !py-2 !px-4 text-xs font-bold">
                Jelajahi Top Up ➔
            </a>
        </div>
    </div>

    {{-- Floating / Inline Share Buttons --}}
    <div class="my-8 flex flex-wrap items-center justify-between gap-4 border-t border-b border-slate-800 py-4 text-xs text-slate-400">
        <span class="font-semibold text-slate-200">Bagikan artikel ini:</span>
        <div class="flex items-center gap-2">
            <a href="https://api.whatsapp.com/send?text={{ urlencode($article->title . ' ' . $currentUrl) }}"
               target="_blank" rel="noopener noreferrer"
               class="flex items-center gap-1.5 rounded-xl border border-slate-700 bg-slate-800/80 px-3 py-2 text-slate-200 hover:border-emerald-500 hover:text-emerald-400 transition">
                <span>💬 WhatsApp</span>
            </a>
            <a href="https://www.facebook.com/sharer/sharer.php?u={{ urlencode($currentUrl) }}"
               target="_blank" rel="noopener noreferrer"
               class="flex items-center gap-1.5 rounded-xl border border-slate-700 bg-slate-800/80 px-3 py-2 text-slate-200 hover:border-blue-500 hover:text-blue-400 transition">
                <span>📘 Facebook</span>
            </a>
            <button type="button" @click="copyLink()"
                    class="flex items-center gap-1.5 rounded-xl border border-slate-700 bg-slate-800/80 px-3 py-2 text-slate-200 hover:border-primary hover:text-primary transition">
                <span x-text="copied ? '✅ Tersalin!' : '🔗 Salin Link'"></span>
            </button>
        </div>
    </div>

    {{-- Related Articles --}}
    @if ($relatedArticles->isNotEmpty())
        <section class="mt-12 space-y-4">
            <h2 class="font-heading text-lg font-bold text-white">Artikel Terkait</h2>
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                @foreach ($relatedArticles as $rel)
                    <x-article-card :article="$rel" />
                @endforeach
            </div>
        </section>
    @endif
</div>
@endsection
