@php
    $siteName = \App\Models\SiteSetting::get('site_name') ?? 'CAPTSHOP';
    $siteLogo = \App\Models\SiteSetting::get('site_logo');
    $siteFavicon = \App\Models\SiteSetting::get('site_favicon');
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title>@yield('title', 'Dashboard') — Admin {{ $siteName }}</title>
    @if ($siteFavicon)
        <link rel="icon" href="{{ $siteFavicon }}">
    @endif
    <link rel="apple-touch-icon" href="/favicon-192x192.png">
    <link rel="manifest" href="/admin-manifest.json">
    <meta name="theme-color" content="#D92525">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="CAPT Admin">

    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-slate-900 text-slate-100 antialiased" x-data="{ sidebarOpen: false }">

@php
    $admin = auth('admin')->user();

    $netflixReady = \App\Models\NetflixAccount::where('status', 'ready')->count();
    $disneyReady = \App\Models\DisneyAccount::where('status', 'ready')->count();
    $canvaReady = \App\Models\CanvaAccount::where('status', 'ready')->count();

    // Menu hanya tampil bila role admin memiliki izinnya
    $menus = [
        [
            'label' => 'Dashboard',
            'route' => 'admin.dashboard',
            'svg' => '<path stroke-linecap="round" stroke-linejoin="round" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"/>',
            'can' => null,
            'pattern' => 'admin.dashboard'
        ],
        [
            'label' => 'Kategori',
            'route' => 'admin.categories.index',
            'svg' => '<path stroke-linecap="round" stroke-linejoin="round" d="M4 6h16M4 10h16M4 14h16M4 18h16"/>',
            'can' => 'categories.manage',
            'pattern' => 'admin.categories.*'
        ],
        [
            'label' => 'Produk',
            'route' => 'admin.products.index',
            'svg' => '<path stroke-linecap="round" stroke-linejoin="round" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/>',
            'can' => 'products.manage',
            'pattern' => 'admin.products.*'
        ],
        [
            'label' => 'Item / SKU',
            'route' => 'admin.product-items.index',
            'svg' => '<path stroke-linecap="round" stroke-linejoin="round" d="M7 7h.01M7 3h5c.512 0 1.024.195 1.414.586l7 7a2 2 0 010 2.828l-7 7a2 2 0 01-2.828 0l-7-7A1.994 1.994 0 013 12V7a4 4 0 014-4z"/>',
            'can' => 'product-items.manage',
            'pattern' => 'admin.product-items.*'
        ],
        [
            'label' => 'Stok Prem',
            'svg' => '<path stroke-linecap="round" stroke-linejoin="round" d="M5 3v4M3 5h4M6 17v4m-2-2h4m5-16l2.286 6.857L21 12l-5.714 2.143L13 21l-2.286-6.857L5 12l5.714-2.143L13 3z"/>',
            'can' => 'products.manage',
            'patterns' => ['admin.netflix-accounts.*', 'admin.disney-accounts.*', 'admin.canva-accounts.*'],
            'children' => [
                ['label' => 'Stok Netflix', 'route' => 'admin.netflix-accounts.index', 'count' => $netflixReady, 'pattern' => 'admin.netflix-accounts.*'],
                ['label' => 'Stok Disney', 'route' => 'admin.disney-accounts.index', 'count' => $disneyReady, 'pattern' => 'admin.disney-accounts.*'],
                ['label' => 'Stok Canva', 'route' => 'admin.canva-accounts.index', 'count' => $canvaReady, 'pattern' => 'admin.canva-accounts.*'],
            ],
        ],
        [
            'label' => 'Transaksi',
            'route' => 'admin.transactions.index',
            'svg' => '<path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>',
            'can' => 'transactions.view',
            'pattern' => 'admin.transactions.*'
        ],
        [
            'label' => 'Banner',
            'route' => 'admin.banners.index',
            'svg' => '<path stroke-linecap="round" stroke-linejoin="round" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"/>',
            'can' => 'banners.manage',
            'pattern' => 'admin.banners.*'
        ],
        [
            'label' => 'Artikel',
            'route' => 'admin.articles.index',
            'svg' => '<path stroke-linecap="round" stroke-linejoin="round" d="M19 20H5a2 2 0 01-2-2V6a2 2 0 012-2h10a2 2 0 012 2v1m2 13a2 2 0 01-2-2V7m2 13a2 2 0 002-2V9a2 2 0 00-2-2h-2m-4-3H9M7 16h6M7 8h6v4H7V8z"/>',
            'can' => 'articles.manage',
            'pattern' => 'admin.articles.*'
        ],
        [
            'label' => 'Kupon',
            'route' => 'admin.coupons.index',
            'svg' => '<path stroke-linecap="round" stroke-linejoin="round" d="M15 5v2m0 4v2m0 4v2M5 5a2 2 0 00-2 2v3a2 2 0 110 4v3a2 2 0 002 2h14a2 2 0 002-2v-3a2 2 0 110-4V7a2 2 0 00-2-2H5z"/>',
            'can' => 'coupons.manage',
            'pattern' => 'admin.coupons.*'
        ],
        [
            'label' => 'Channel Bayar',
            'route' => 'admin.payment-channels.index',
            'svg' => '<path stroke-linecap="round" stroke-linejoin="round" d="M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z"/>',
            'can' => 'payment-channels.manage',
            'pattern' => 'admin.payment-channels.*'
        ],
        [
            'label' => 'Akun Admin',
            'route' => 'admin.accounts.index',
            'svg' => '<path stroke-linecap="round" stroke-linejoin="round" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z"/>',
            'can' => 'admin.accounts',
            'pattern' => 'admin.accounts.*'
        ],
        [
            'label' => 'Pengaturan',
            'route' => 'admin.settings.edit',
            'svg' => '<path stroke-linecap="round" stroke-linejoin="round" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>',
            'can' => 'admin.settings',
            'pattern' => 'admin.settings.*'
        ],
    ];

    if ($admin?->role === 'writer') {
        $menus = [
            [
                'label' => 'Artikel',
                'route' => 'admin.articles.index',
                'svg' => '<path stroke-linecap="round" stroke-linejoin="round" d="M19 20H5a2 2 0 01-2-2V6a2 2 0 012-2h10a2 2 0 012 2v1m2 13a2 2 0 01-2-2V7m2 13a2 2 0 002-2V9a2 2 0 00-2-2h-2m-4-3H9M7 16h6M7 8h6v4H7V8z"/>',
                'can' => 'articles.manage',
                'pattern' => 'admin.articles.*'
            ],
        ];
    } else {
        $menus = array_filter($menus, fn ($menu) => $menu['can'] === null || $admin?->can($menu['can']));
    }
@endphp

{{-- Overlay mobile --}}
<div x-show="sidebarOpen" x-transition.opacity class="fixed inset-0 z-40 bg-black/70 lg:hidden" @click="sidebarOpen = false" x-cloak></div>

<div class="flex min-h-screen">
    {{-- Sidebar --}}
    <aside
        class="fixed inset-y-0 left-0 z-50 w-64 transform border-r border-slate-800 bg-slate-950 shadow-2xl transition-transform duration-200 lg:static lg:translate-x-0"
        :class="sidebarOpen ? 'translate-x-0' : '-translate-x-full'"
        :style="sidebarOpen ? 'transform: translateX(0) !important;' : ''"
        x-cloak
    >
        <div class="flex h-16 items-center justify-between border-b border-slate-800 px-5">
            <div class="flex items-center gap-2">
                @if ($siteLogo)
                    <img src="{{ $siteLogo }}" alt="{{ $siteName }}" class="h-8 w-auto max-w-[6rem] object-contain">
                @else
                    <span class="grid h-8 w-8 place-items-center rounded-lg bg-primary font-heading text-base font-bold text-white">C</span>
                @endif
                <div class="leading-tight">
                    <p class="font-heading text-sm font-bold">{{ $siteName }}</p>
                    <p class="text-[11px] text-slate-500">Panel Admin</p>
                </div>
            </div>
            <button type="button" @click="sidebarOpen = false" class="rounded-lg p-1.5 text-slate-400 hover:bg-slate-800 hover:text-white lg:hidden" aria-label="Tutup menu">
                <svg class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
            </button>
        </div>

        <nav class="space-y-1 p-3 pb-24 overflow-y-auto max-h-[calc(100vh-4rem)]">
            @foreach ($menus as $menu)
                @if (isset($menu['children']))
                    @php
                        $isActive = request()->routeIs(...($menu['patterns'] ?? []));
                    @endphp
                    <div x-data="{ open: {{ $isActive ? 'true' : 'false' }} }" class="space-y-1">
                        <button
                            type="button"
                            @click="open = !open"
                            class="flex w-full items-center justify-between rounded-lg px-3 py-2 text-sm font-medium transition {{ $isActive ? 'bg-white/10 text-white' : 'text-slate-400 hover:bg-slate-800/60 hover:text-slate-200' }}"
                        >
                            <span class="flex items-center gap-3">
                                <svg class="h-4 w-4 shrink-0 transition text-slate-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                    {!! $menu['svg'] !!}
                                </svg>
                                <span>{{ $menu['label'] }}</span>
                            </span>
                            <svg class="h-4 w-4 transition-transform duration-200 text-slate-500" :class="open ? 'rotate-180 text-slate-300' : ''" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
                            </svg>
                        </button>
                        <div x-show="open" x-cloak style="margin-left: 1.25rem; padding-left: 0.75rem; border-left: 1px solid #334155;" class="space-y-1 pt-1">
                            @foreach ($menu['children'] as $child)
                                @php
                                    $isChildActive = request()->routeIs($child['pattern']);
                                @endphp
                                <a
                                    href="{{ route($child['route']) }}"
                                    class="flex items-center justify-between rounded-lg px-2.5 py-1.5 text-xs font-medium transition {{ $isChildActive ? 'bg-primary/20 text-white font-semibold' : 'text-slate-400 hover:bg-slate-800/60 hover:text-slate-200' }}"
                                >
                                    <span>{{ $child['label'] }}</span>
                                    @if(isset($child['count']))
                                        <span class="text-[10px] px-1.5 py-0.5 rounded font-mono font-bold {{ $child['count'] > 0 ? 'bg-emerald-500/15 text-emerald-400' : 'bg-slate-800 text-slate-500' }}">
                                            {{ $child['count'] }}
                                        </span>
                                    @endif
                                </a>
                            @endforeach
                        </div>
                    </div>
                @else
                    @php
                        $isItemActive = request()->routeIs($menu['pattern']);
                    @endphp
                    <a
                        href="{{ route($menu['route']) }}"
                        class="flex items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium transition {{ $isItemActive ? 'bg-primary/15 text-white font-semibold' : 'text-slate-400 hover:bg-slate-800/60 hover:text-slate-200' }}"
                    >
                        <svg class="h-4 w-4 shrink-0 transition {{ $isItemActive ? 'text-primary-light' : 'text-slate-400' }}" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            {!! $menu['svg'] !!}
                        </svg>
                        <span>{{ $menu['label'] }}</span>
                    </a>
                @endif
            @endforeach
        </nav>

        <div class="absolute inset-x-0 bottom-0 border-t border-slate-800 p-3">
            <div class="flex items-center gap-3 rounded-lg px-3 py-2">
                <span class="grid h-9 w-9 shrink-0 place-items-center rounded-full bg-primary/20 font-heading text-sm font-bold text-primary-light">
                    {{ strtoupper(substr($admin?->name ?? 'A', 0, 1)) }}
                </span>
                <div class="min-w-0 flex-1">
                    <p class="truncate text-sm font-semibold text-slate-200">{{ $admin?->name }}</p>
                    <p class="text-[11px] uppercase text-slate-500">{{ str_replace('_', ' ', $admin?->role ?? '') }}</p>
                </div>
                <form action="{{ route('admin.logout') }}" method="POST">
                    @csrf
                    <button type="submit" title="Logout" class="rounded-lg p-2 text-slate-400 transition hover:bg-red-500/15 hover:text-red-400">
                        <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1" />
                        </svg>
                    </button>
                </form>
            </div>
        </div>
    </aside>

    {{-- Area konten --}}
    <div class="flex min-w-0 flex-1 flex-col">
        {{-- Topbar --}}
        <header class="sticky top-0 z-20 flex h-16 items-center justify-between border-b border-slate-800 bg-slate-900/95 px-4 backdrop-blur lg:px-6">
            <button class="rounded-lg p-2 text-slate-400 hover:bg-slate-800 lg:hidden" @click="sidebarOpen = true" aria-label="Buka menu">
                <svg class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" d="M4 6h16M4 12h16M4 18h16"/></svg>
            </button>

            <h1 class="font-heading text-base font-bold text-slate-100">@yield('page-title', 'Dashboard')</h1>

            <a href="{{ route('home') }}" target="_blank" class="hidden rounded-lg border border-slate-700 px-3 py-1.5 text-xs font-medium text-slate-300 transition hover:border-primary hover:text-white sm:block">
                Lihat Situs ↗
            </a>
        </header>

        {{-- Flash messages --}}
        @if (session('success') || session('error'))
            <div class="px-4 pt-4 lg:px-6">
                @if (session('success'))
                    <div class="rounded-lg border border-secondary/40 bg-secondary/10 px-4 py-3 text-sm text-emerald-300">{{ session('success') }}</div>
                @endif
                @if (session('error'))
                    <div class="rounded-lg border border-red-500/40 bg-red-500/10 px-4 py-3 text-sm text-red-300">{{ session('error') }}</div>
                @endif
            </div>
        @endif

        <main class="min-w-0 flex-1 p-4 lg:p-6">
            @yield('content')
        </main>
    </div>
</div>

</body>
</html>
