@extends('layouts.app')

@section('title', 'Kabar & Tips Game — Blog Resmi CAPTSHOP')
@section('meta_description', 'Kumpulan berita esports terbaru, tips trik hero Mobile Legends, update event Booyah Pass Free Fire, dan promo diskon voucher game di CAPTSHOP.')

@section('content')
<div class="space-y-8">
    {{-- Header --}}
    <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <span class="inline-flex items-center gap-1.5 rounded-full border border-primary/30 bg-primary/10 px-3 py-1 text-xs font-semibold text-primary-light">
                📰 Blog &amp; Berita Gaming
            </span>
            <h1 class="mt-2 font-heading text-2xl font-black text-slate-100 sm:text-3xl">
                Kabar &amp; Tips Gamer
            </h1>
            <p class="mt-1 text-xs text-slate-400">
                Update event resmi, bocoran promo diamond, panduan meta hero, dan berita game terkini.
            </p>
        </div>

        {{-- Search Bar Artikel --}}
        <form action="{{ route('articles.index') }}" method="GET" class="relative w-full sm:w-72">
            <input type="text" name="q" value="{{ $search ?? '' }}" placeholder="Cari artikel..."
                   class="w-full rounded-xl border border-slate-700 bg-slate-800/80 pl-9 pr-4 py-2 text-xs text-slate-200 placeholder-slate-500 focus:border-primary focus:outline-none">
            <svg class="pointer-events-none absolute left-3 top-2.5 h-4 w-4 text-slate-500" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="11" cy="11" r="7"/><path d="m21 21-4.3-4.3"/></svg>
        </form>
    </div>

    {{-- Filter Category Pills --}}
    @if ($categories->isNotEmpty())
        <div class="flex items-center gap-2 overflow-x-auto pb-2 scrollbar-none text-xs">
            <a href="{{ route('articles.index') }}"
               class="rounded-xl px-4 py-2 font-semibold transition shrink-0 {{ empty($category) ? 'bg-primary text-white shadow-md' : 'border border-slate-700 bg-slate-800/60 text-slate-400 hover:text-white hover:bg-slate-800' }}">
                Semua
            </a>
            @foreach ($categories as $cat)
                <a href="{{ route('articles.index', ['kategori' => $cat]) }}"
                   class="rounded-xl px-4 py-2 font-semibold transition shrink-0 {{ ($category === $cat) ? 'bg-primary text-white shadow-md' : 'border border-slate-700 bg-slate-800/60 text-slate-400 hover:text-white hover:bg-slate-800' }}">
                    {{ $cat }}
                </a>
            @endforeach
        </div>
    @endif

    {{-- Hero Featured Article (Jika ada) --}}
    @if ($featured)
        <div class="overflow-hidden rounded-3xl border border-slate-800 bg-slate-900/80 shadow-2xl transition hover:border-primary/50">
            <div class="grid grid-cols-1 lg:grid-cols-12 gap-0">
                <a href="{{ route('articles.show', $featured->slug) }}" class="relative aspect-video w-full overflow-hidden bg-slate-950 lg:col-span-7">
                    @if ($featured->thumbnailUrl())
                        <img src="{{ $featured->thumbnailUrl() }}" alt="{{ $featured->title }}" class="h-full w-full object-cover transition duration-500 hover:scale-105">
                    @else
                        <div class="grid h-full w-full place-items-center bg-gradient-to-br from-slate-900 to-slate-950 text-5xl">
                            🎮
                        </div>
                    @endif
                    <span class="absolute left-4 top-4 rounded-xl bg-accent px-3 py-1 text-xs font-bold text-slate-950 shadow-md">
                        🔥 TRENDING
                    </span>
                </a>

                <div class="flex flex-col justify-between p-6 sm:p-8 lg:col-span-5">
                    <div>
                        <div class="flex items-center gap-2 text-xs text-primary-light font-semibold mb-2">
                            <span>{{ $featured->category ?? 'Game Update' }}</span>
                            <span>•</span>
                            <span class="text-slate-400">{{ $featured->reading_time_minutes ?? 2 }} menit baca</span>
                        </div>
                        <h2 class="font-heading text-xl sm:text-2xl font-bold text-white leading-snug hover:text-primary-light transition">
                            <a href="{{ route('articles.show', $featured->slug) }}">
                                {{ $featured->title }}
                            </a>
                        </h2>
                        <p class="mt-3 text-sm text-slate-400 leading-relaxed line-clamp-3">
                            {{ $featured->excerpt ?? Str::limit(strip_tags($featured->body), 180) }}
                        </p>
                    </div>

                    <div class="mt-6 flex items-center justify-between border-t border-slate-800 pt-4 text-xs text-slate-500">
                        <span class="text-slate-300 font-medium">{{ $featured->author?->name ?? 'Admin CAPTSHOP' }}</span>
                        <time>{{ $featured->published_at?->format('d M Y') }}</time>
                    </div>
                </div>
            </div>
        </div>
    @endif

    {{-- Grid Artikel --}}
    @if ($articles->isNotEmpty())
        <div class="grid grid-cols-1 gap-6 sm:grid-cols-2 lg:grid-cols-3">
            @foreach ($articles as $article)
                <x-article-card :article="$article" />
            @endforeach
        </div>

        <div class="mt-8">
            {{ $articles->links() }}
        </div>
    @else
        <div class="rounded-3xl border border-dashed border-slate-800 p-12 text-center">
            <span class="text-4xl">📝</span>
            <p class="mt-3 text-base font-bold text-slate-200">Belum ada artikel ditemukan</p>
            <p class="mt-1 text-xs text-slate-500">Silakan kembali lagi nanti untuk membaca update artikel terbaru.</p>
        </div>
    @endif
</div>
@endsection
