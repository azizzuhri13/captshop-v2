<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title>Login Admin — {{ \App\Models\SiteSetting::get('site_name') ?? 'CaptShop' }}</title>

    @vite(['resources/css/app.css'])
</head>
<body class="grid min-h-screen place-items-center bg-slate-900 p-4">

    <div class="w-full max-w-sm">
        <div class="mb-6 text-center">
            <span class="grid mx-auto h-12 w-12 place-items-center rounded-2xl bg-primary font-heading text-2xl font-bold text-white">C</span>
            <h1 class="mt-3 font-heading text-xl font-bold text-slate-100">Panel Admin</h1>
            <p class="mt-1 text-sm text-slate-500">{{ \App\Models\SiteSetting::get('site_name') ?? 'CaptShop' }}</p>
        </div>

        <form action="{{ route('admin.login') }}" method="POST" class="space-y-4 rounded-2xl border border-slate-800 bg-slate-800/40 p-6">
            @csrf

            <div>
                <label for="email" class="mb-1.5 block text-sm font-medium text-slate-300">Email</label>
                <input type="email" id="email" name="email" value="{{ old('email') }}" required autofocus class="input-dark" placeholder="admin@captshop.test">
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
