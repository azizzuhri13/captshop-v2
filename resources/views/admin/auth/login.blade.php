<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="robots" content="noindex, nofollow">

    <title>Login Admin — {{ \App\Models\SiteSetting::get('site_name') ?? 'CAPTSHOP' }}</title>
    <link rel="icon" type="image/x-icon" href="/favicon.ico">
    <link rel="icon" type="image/png" sizes="192x192" href="/favicon-192x192.png">
    <link rel="apple-touch-icon" href="/favicon-192x192.png">
    <link rel="manifest" href="/admin-manifest.json">
    <meta name="theme-color" content="#D92525">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="CAPT Admin">

    @vite(['resources/css/app.css'])
</head>
<body class="grid min-h-screen place-items-center bg-slate-900 p-4">

    <div class="w-full max-w-sm">
        <div class="mb-6 text-center">
            @if ($logo = \App\Models\SiteSetting::get('site_logo'))
                <img src="{{ $logo }}" alt="CAPTSHOP" class="mx-auto h-14 w-auto object-contain mb-2">
            @else
                <img src="/storage/settings/0jaI9QmdPgiC9Tnp2STxhRcEqMOvMnNkR5ikk9aX.png" alt="CAPTSHOP" class="mx-auto h-14 w-auto object-contain mb-2">
            @endif
            <h1 class="mt-2 font-heading text-xl font-bold text-slate-100">Panel Admin</h1>
            <p class="mt-1 text-sm text-slate-500">{{ \App\Models\SiteSetting::get('site_name') ?? 'CAPTSHOP' }}</p>
        </div>

        <form action="{{ route('admin.login') }}" method="POST" class="space-y-4 rounded-2xl border border-slate-800 bg-slate-800/40 p-6">
            @csrf

            <div>
                <label for="email" class="mb-1.5 block text-sm font-medium text-slate-300">Email</label>
                <input type="email" id="email" name="email" value="{{ old('email') }}" required autofocus class="input-dark" placeholder="admin@captshop.id">
            </div>

            <div>
                <label for="password" class="mb-1.5 block text-sm font-medium text-slate-300">Password</label>
                <input type="password" id="password" name="password" required class="input-dark" placeholder="••••••••">
            </div>

            <label class="flex items-center gap-2 text-sm text-slate-400">
                <input type="checkbox" name="remember" class="rounded border-slate-600 bg-slate-800 text-primary focus:ring-primary">
                Ingat saya
            </label>

            @if ($errors->any())
                <div class="rounded-lg border border-red-500/40 bg-red-500/10 px-4 py-3 text-sm text-red-300">
                    @foreach ($errors->all() as $error)
                        <p>{{ $error }}</p>
                    @endforeach
                </div>
            @endif

            <button type="submit" class="btn-primary w-full">Masuk</button>
        </form>

        <p class="mt-4 text-center text-xs text-slate-600">
            <a href="{{ route('home') }}" class="hover:text-slate-400">← Kembali ke situs</a>
        </p>
    </div>

</body>
</html>
