@php
    $siteName = \App\Models\SiteSetting::get('site_name') ?? 'CaptShop';
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

    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-slate-900 text-slate-100 antialiased" x-data="{ sidebarOpen: false }">

@php
    $admin = auth('admin')->user();

    // Menu hanya tampil bila role admin memiliki izinnya (Gate guard default
    // mengikuti session guard admin karena Gate::before di AppServiceProvider
    // memeriksa model Admin).
    $menus = [
        ['label' => 'Dashboard', 'route' => 'admin.dashboard', 'icon' => '📊', 'can' => null, 'pattern' => 'admin.dashboard'],
        ['label' => 'Kategori', 'route' => 'admin.categories.index', 'icon' => '🗂️', 'can' => 'categories.manage', 'pattern' => 'admin.categories.*'],
        ['label' => 'Produk', 'route' => 'admin.products.index', 'icon' => '🎮', 'can' => 'products.manage', 'pattern' => 'admin.products.*'],
        ['label' => 'Item / SKU', 'route' => 'admin.product-items.index', 'icon' => '💎', 'can' => 'product-items.manage', 'pattern' => 'admin.product-items.*'],
        ['label' => 'Transaksi', 'route' => 'admin.transactions.index', 'icon' => '🧾', 'can' => 'transactions.view', 'pattern' => 'admin.transactions.*'],
        ['label' => 'Banner', 'route' => 'admin.banners.index', 'icon' => '🖼️', 'can' => 'banners.manage', 'pattern' => 'admin.banners.*'],
        ['label' => 'Kupon', 'route' => 'admin.coupons.index', 'icon' => '🎟️', 'can' => 'coupons.manage', 'pattern' => 'admin.coupons.*'],
        ['label' => 'Channel Bayar', 'route' => 'admin.payment-channels.index', 'icon' => '💳', 'can' => 'payment-channels.manage', 'pattern' => 'admin.payment-channels.*'],
        ['label' => 'Akun Admin', 'route' => 'admin.accounts.index', 'icon' => '👤', 'can' => 'admin.accounts', 'pattern' => 'admin.accounts.*'],
        ['label' => 'Pengaturan', 'route' => 'admin.settings.edit', 'icon' => '⚙️', 'can' => 'admin.settings', 'pattern' => 'admin.settings.*'],
    ];

    $menus = array_filter($menus, fn ($menu) => $menu['can'] === null || $admin?->can($menu['can']));
@endphp

{{-- Overlay mobile --}}
<div x-show="sidebarOpen" x-transition.opacity class="fixed inset-0 z-30 bg-black/60 lg:hidden" @click="sidebarOpen = false" x-cloak></div>

<div class="flex min-h-screen">
    {{-- Sidebar --}}
    <aside
        class="fixed inset-y-0 left-0 z-40 w-64 transform border-r border-slate-800 bg-slate-950 transition-transform lg:static lg:translate-x-0"
        :class="sidebarOpen ? 'translate-x-0' : '-translate-x-full'"
    >
        <div class="flex h-16 items-center gap-2 border-b border-slate-800 px-5">
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

        <nav class="space-y-1 p-3">
            @foreach ($menus as $menu)
                <a
                    href="{{ route($menu['route']) }}"
                    class="flex items-center gap-3 rounded-lg px-3 py-2.5 text-sm font-medium transition {{ request()->routeIs($menu['pattern']) ? 'bg-primary/15 text-white' : 'text-slate-400 hover:bg-slate-800 hover:text-slate-100' }}"
                >
                    <span>{{ $menu['icon'] }}</span>
                    {{ $menu['label'] }}
                </a>
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
                    <button type="submit" title="Logout" class="rounded-lg p-2 text-slate-500 transition hover:bg-slate-800 hover:text-red-400">⏻</button>
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
