@php
    $siteName = \App\Models\SiteSetting::get('site_name') ?? 'CAPTSHOP';
    $siteLogo = \App\Models\SiteSetting::get('site_logo');
    $siteFavicon = \App\Models\SiteSetting::get('site_favicon');
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title>@yield('title', config('app.name', 'CAPTSHOP'))</title>
    @if ($siteFavicon)
        <link rel="icon" href="{{ $siteFavicon }}">
    @endif
    <meta name="description" content="@yield('meta_description', 'Top-up game, voucher digital & PPOB tercepat, termurah, dan aman.')">
    <link rel="canonical" href="@yield('canonical', url()->current())">

    @php
        // Open Graph: nilai spesifik halaman via @section('og') (JSON), fallback
        // nilai umum seluruh situs.
        $og = json_decode(trim((string) View::getSection('og')), true) ?: [];
        $ogTitle = $og['title'] ?? (trim((string) View::getSection('title')) ?: config('app.name', 'CAPTSHOP'));
        $ogDescription = $og['description'] ?? (trim((string) View::getSection('meta_description')) ?: 'Top-up game, voucher digital & PPOB tercepat, termurah, dan aman.');
        $ogImage = $og['image'] ?? null;
        $ogUrl = $og['url'] ?? url()->current();
        $ogType = $og['type'] ?? 'website';
    @endphp
    <meta property="og:title" content="{{ $ogTitle }}">
    <meta property="og:description" content="{{ $ogDescription }}">
    <meta property="og:type" content="{{ $ogType }}">
    <meta property="og:url" content="{{ $ogUrl }}">
    <meta property="og:site_name" content="{{ $siteName }}">
    @if ($ogImage)
        <meta property="og:image" content="{{ $ogImage }}">
    @endif
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="{{ $ogTitle }}">
    <meta name="twitter:description" content="{{ $ogDescription }}">
    @if ($ogImage)
        <meta name="twitter:image" content="{{ $ogImage }}">
    @endif

    <meta name="theme-color" content="#D92525">
    <link rel="manifest" href="/manifest.json">
    <link rel="apple-touch-icon" href="/icon-192.png">

    @vite(['resources/css/app.css', 'resources/js/app.js'])

    @stack('head')
</head>
<body class="min-h-screen bg-slate-900 text-slate-100 antialiased">

{{-- Header: topbar (logo — pencarian — aksi) + dropdown mobile --}}
<header
    x-data="{ mobileMenu: false }"
    @click.outside="mobileMenu = false"
    @keydown.escape.window="mobileMenu = false"
    class="sticky top-0 z-40 border-b border-slate-800 bg-slate-900/95 backdrop-blur supports-[backdrop-filter]:bg-slate-900/80"
>
    {{-- Topbar --}}
    <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
        <div class="flex items-center justify-between py-3">
            {{-- Logo (kiri) --}}
            <a href="{{ route('home') }}" class="flex shrink-0 items-center gap-2">
                @if ($siteLogo)
                    <img src="{{ $siteLogo }}" alt="{{ $siteName }}" class="h-9 w-auto max-w-[8rem] object-contain">
                @else
                    <span class="grid h-9 w-9 place-items-center rounded-xl bg-primary font-heading text-lg font-bold text-white">C</span>
                @endif
                <span class="hidden font-heading text-lg font-bold sm:block">{{ $siteName }}</span>
            </a>

            {{-- Pencarian (tengah, fleksibel) --}}
            <form action="{{ route('home') }}" method="GET" class="relative mx-4 max-w-2xl flex-1 lg:mx-8" role="search">
                <input
                    type="search"
                    name="q"
                    value="{{ request('q', '') }}"
                    placeholder="Cari game, pulsa, PLN..."
                    class="w-full rounded-full border border-slate-700 bg-slate-800 py-2 pl-10 pr-4 text-sm text-slate-100 placeholder-slate-500 focus:border-primary focus:outline-none"
                    autocomplete="off"
                >
                <svg class="pointer-events-none absolute left-3.5 top-2.5 h-4 w-4 text-slate-500" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="11" cy="11" r="7"/><path d="m21 21-4.3-4.3"/></svg>
            </form>

            {{-- Navigasi desktop (kanan) --}}
            <nav class="hidden shrink-0 items-center gap-4 md:flex" aria-label="Navigasi utama">
                <a href="{{ route('home') }}" class="text-sm font-medium transition {{ request()->routeIs('home') ? 'text-white' : 'text-slate-400 hover:text-slate-100' }}">
                    Beranda
                </a>
                <a href="{{ route('track.index') }}" class="text-sm font-medium transition {{ request()->routeIs('track.*') ? 'text-white' : 'text-slate-400 hover:text-slate-100' }}">
                    Cek Transaksi
                </a>
                <a href="{{ route('tools.calculator') }}" class="text-sm font-medium transition {{ request()->routeIs('tools.calculator') ? 'text-white' : 'text-slate-400 hover:text-slate-100' }}">
                    Kalkulator WR
                </a>
                <a href="{{ route('articles.index') }}" class="text-sm font-medium transition {{ request()->routeIs('articles.*') ? 'text-white' : 'text-slate-400 hover:text-slate-100' }}">
                    Artikel
                </a>

                @auth('web')
                    <div x-data="{ userMenu: false }" @click.outside="userMenu = false" class="relative">
                        <button
                            type="button"
                            @click="userMenu = !userMenu"
                            class="flex items-center gap-2 rounded-xl border border-slate-700 bg-slate-800/80 px-3 py-1.5 text-xs font-semibold text-slate-100 transition hover:bg-slate-700"
                        >
                            @if (auth('web')->user()->avatar)
                                <img src="{{ auth('web')->user()->avatar }}" alt="{{ auth('web')->user()->name }}" class="h-6 w-6 rounded-full object-cover">
                            @else
                                <span class="grid h-6 w-6 place-items-center rounded-full bg-primary/20 text-xs font-bold text-primary">
                                    {{ strtoupper(substr(auth('web')->user()->name, 0, 1)) }}
                                </span>
                            @endif
                            <span class="max-w-[100px] truncate">{{ auth('web')->user()->name }}</span>
                            <svg class="h-3.5 w-3.5 text-slate-400 transition" :class="userMenu ? 'rotate-180' : ''" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
                        </button>

                        <div
                            x-show="userMenu"
                            x-cloak
                            x-transition
                            class="absolute right-0 mt-2 w-48 rounded-xl border border-slate-700 bg-slate-800 p-1.5 shadow-xl"
                        >
                            <div class="px-3 py-2 border-b border-slate-700/60 mb-1">
                                <p class="text-xs font-bold text-slate-200 truncate">{{ auth('web')->user()->name }}</p>
                                <p class="text-[10px] text-slate-400 truncate">{{ auth('web')->user()->email }}</p>
                            </div>
                            <form action="{{ route('logout') }}" method="POST">
                                @csrf
                                <button type="submit" class="flex w-full items-center gap-2 rounded-lg px-3 py-2 text-xs font-medium text-red-400 transition hover:bg-red-500/10">
                                    <span>🚪</span>
                                    <span>Keluar</span>
                                </button>
                            </form>
                        </div>
                    </div>
                @else
                    <a href="{{ route('login') }}" class="rounded-xl bg-primary px-5 py-2 text-sm font-semibold text-white transition hover:bg-primary-dark shadow-sm">
                        Masuk / Daftar
                    </a>
                @endauth
            </nav>

            {{-- Tombol hamburger (khusus mobile) --}}
            <button
                type="button"
                class="grid h-10 w-10 shrink-0 place-items-center rounded-xl text-slate-400 transition hover:bg-slate-800 hover:text-slate-100 md:hidden"
                @click="mobileMenu = !mobileMenu"
                :aria-expanded="mobileMenu"
                aria-controls="mobile-menu"
                :aria-label="mobileMenu ? 'Tutup menu navigasi' : 'Buka menu navigasi'"
            >
                <svg x-show="!mobileMenu" class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M4 6h16M4 12h16M4 18h16"/></svg>
                <svg x-show="mobileMenu" x-cloak class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12"/></svg>
            </button>
        </div>
    </div>

    {{-- Dropdown menu (khusus mobile) --}}
    <div
        id="mobile-menu"
        x-show="mobileMenu"
        x-cloak
        class="border-t border-slate-800 bg-slate-900 px-4 py-3 md:hidden"
        aria-label="Navigasi utama"
    >
        <nav class="space-y-1">
            <a href="{{ route('home') }}" class="block rounded-lg px-3 py-2.5 text-sm font-medium transition {{ request()->routeIs('home') ? 'bg-primary/15 text-white' : 'text-slate-400 hover:bg-slate-800 hover:text-slate-100' }}">
                Beranda
            </a>
            <a href="{{ route('track.index') }}" class="block rounded-lg px-3 py-2.5 text-sm font-medium transition {{ request()->routeIs('track.*') ? 'bg-primary/15 text-white' : 'text-slate-400 hover:bg-slate-800 hover:text-slate-100' }}">
                Cek Transaksi
            </a>
            <a href="{{ route('tools.calculator') }}" class="block rounded-lg px-3 py-2.5 text-sm font-medium transition {{ request()->routeIs('tools.calculator') ? 'bg-primary/15 text-white' : 'text-slate-400 hover:bg-slate-800 hover:text-slate-100' }}">
                🎮 Kalkulator Winrate
            </a>
            <a href="{{ route('articles.index') }}" class="block rounded-lg px-3 py-2.5 text-sm font-medium transition {{ request()->routeIs('articles.*') ? 'bg-primary/15 text-white' : 'text-slate-400 hover:bg-slate-800 hover:text-slate-100' }}">
                📰 Kabar &amp; Tips Game
            </a>

            @auth('web')
                <div class="border-t border-slate-800 pt-2 mt-2">
                    <div class="px-3 py-1.5 flex items-center gap-2.5">
                        @if (auth('web')->user()->avatar)
                            <img src="{{ auth('web')->user()->avatar }}" alt="{{ auth('web')->user()->name }}" class="h-7 w-7 rounded-full object-cover">
                        @else
                            <span class="grid h-7 w-7 place-items-center rounded-full bg-primary/20 text-xs font-bold text-primary">
                                {{ strtoupper(substr(auth('web')->user()->name, 0, 1)) }}
                            </span>
                        @endif
                        <div class="truncate">
                            <p class="text-xs font-bold text-slate-200 truncate">{{ auth('web')->user()->name }}</p>
                            <p class="text-[10px] text-slate-400 truncate">{{ auth('web')->user()->email }}</p>
                        </div>
                    </div>
                    <form action="{{ route('logout') }}" method="POST" class="mt-1">
                        @csrf
                        <button type="submit" class="w-full text-left rounded-lg px-3 py-2 text-xs font-medium text-red-400 hover:bg-red-500/10 transition">
                            🚪 Keluar
                        </button>
                    </form>
                </div>
            @else
                <a href="{{ route('login') }}" class="block rounded-xl bg-primary px-3 py-2.5 text-center text-sm font-semibold text-white transition hover:bg-primary-dark">
                    Masuk / Daftar
                </a>
            @endauth
        </nav>
    </div>
</header>

{{-- Flash messages --}}
@if ($errors->any() || session('success') || session('info'))
    <div class="mx-auto max-w-xl px-4 pt-4 lg:max-w-5xl" x-data="{ show: true }" x-show="show" x-transition>
        @if (session('success'))
            <div class="mb-2 flex items-center justify-between gap-2 rounded-lg border border-secondary/40 bg-secondary/10 px-4 py-3 text-sm text-emerald-300">
                <span>{{ session('success') }}</span>
                <button @click="show = false" class="shrink-0 text-emerald-400/60 hover:text-emerald-300">✕</button>
            </div>
        @endif

        @if (session('info'))
            <div class="mb-2 flex items-center justify-between gap-2 rounded-lg border border-slate-700 bg-slate-800 px-4 py-3 text-sm text-slate-300">
                <span>{{ session('info') }}</span>
                <button @click="show = false" class="shrink-0 text-slate-500 hover:text-slate-300">✕</button>
            </div>
        @endif

        @if ($errors->any())
            <div class="mb-2 flex items-start justify-between gap-2 rounded-lg border border-red-500/40 bg-red-500/10 px-4 py-3 text-sm text-red-300">
                <div>
                    <p class="font-semibold">Ups, ada masalah:</p>
                    <ul class="mt-1 list-inside list-disc space-y-0.5">
                        @foreach ($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
                <button @click="show = false" class="shrink-0 text-red-400/60 hover:text-red-300">✕</button>
            </div>
        @endif
    </div>
@endif

{{-- Konten utama --}}
<main class="mx-auto w-full max-w-xl px-4 py-6 lg:max-w-5xl">
    @yield('content')
</main>

{{-- Footer --}}
<footer class="border-t border-slate-800 bg-slate-900">
    <div class="mx-auto max-w-xl px-4 py-8 text-center text-sm text-slate-400 lg:max-w-5xl">
        <p class="font-heading text-base font-semibold text-slate-200">{{ $siteName }}</p>
        <p class="mt-1">Top-up game, voucher digital &amp; PPOB — instan, aman, 24/7.</p>

        <div class="mt-4 flex flex-wrap items-center justify-center gap-x-3 sm:gap-x-5 gap-y-1.5 text-[11px] sm:text-xs font-medium text-slate-400">
            <a href="{{ route('pages.terms') }}" class="transition hover:text-white">Syarat &amp; Ketentuan</a>
            <span class="text-slate-700">•</span>
            <a href="{{ route('pages.privacy') }}" class="transition hover:text-white">Kebijakan Privasi</a>
            <span class="text-slate-700">•</span>
            <a href="{{ route('pages.about') }}" class="transition hover:text-white">Tentang Kami</a>
            <span class="text-slate-700">•</span>
            <a href="{{ route('articles.index') }}" class="transition hover:text-white">Artikel &amp; Berita</a>
            <span class="text-slate-700">•</span>
            <a href="{{ route('pages.faq') }}" class="transition hover:text-white">FAQ</a>
        </div>

        <p class="mt-4 text-xs text-slate-500">
            © {{ now()->year }} {{ $siteName }}. All rights reserved.
        </p>
    </div>
</footer>

<script>
    if ('serviceWorker' in navigator) {
        window.addEventListener('load', () => {
            navigator.serviceWorker.register('/sw.js').catch(() => {});
        });
    }
</script>
</body>
</html>
