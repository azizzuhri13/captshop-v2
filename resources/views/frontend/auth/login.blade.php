@extends('layouts.app')

@section('title', 'Masuk / Daftar — '.($siteName ?? 'CAPTSHOP'))

@section('content')
<div class="mx-auto max-w-md py-6 sm:py-12">
    <div class="rounded-3xl border border-slate-800 bg-slate-900/90 p-6 sm:p-8 shadow-xl backdrop-blur">
        {{-- Header --}}
        <div class="text-center">
            <div class="inline-flex h-14 w-14 items-center justify-center rounded-2xl bg-primary/10 text-2xl text-primary mb-3">
                🔐
            </div>
            <h1 class="font-heading text-xl font-bold text-slate-100 sm:text-2xl">
                Masuk atau Daftar
            </h1>
            <p class="mt-2 text-xs sm:text-sm text-slate-400">
                Akses riwayat transaksi dan kemudahan top up hanya dengan satu klik menggunakan akun Google Anda.
            </p>
        </div>

        {{-- Error Alert --}}
        @if ($errors->has('oauth'))
            <div class="mt-6 rounded-xl border border-red-500/30 bg-red-500/10 p-3.5 text-xs text-red-400 flex items-center gap-2">
                <span class="text-base">⚠️</span>
                <span>{{ $errors->first('oauth') }}</span>
            </div>
        @endif

        {{-- Google Sign-in Button --}}
        <div class="my-6">
            <a
                href="{{ route('auth.google') }}"
                class="flex h-12 w-full items-center justify-center gap-3 rounded-xl bg-white px-5 text-sm font-semibold text-slate-800 shadow-md transition hover:bg-slate-100 focus:outline-none focus:ring-2 focus:ring-primary focus:ring-offset-2 focus:ring-offset-slate-900 active:scale-[0.99]"
            >
                {{-- Official Google Icon SVG --}}
                <svg class="h-5 w-5 shrink-0" viewBox="0 0 24 24">
                    <path fill="#4285F4" d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92c-.26 1.37-1.04 2.53-2.21 3.31v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.09z"/>
                    <path fill="#34A853" d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z"/>
                    <path fill="#FBBC05" d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.06H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.94l2.85-2.22.81-.63z"/>
                    <path fill="#EA4335" d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.06l3.66 2.84c.87-2.6 3.3-4.52 6.16-4.52z"/>
                </svg>
                <span class="text-slate-800 font-semibold" style="color: #1f2937;">Lanjutkan dengan Google</span>
            </a>
        </div>

        {{-- Features / Benefits --}}
        <div class="mt-8 space-y-3.5 border-t border-slate-800/80 pt-6 text-xs text-slate-400">
            <div class="flex items-center gap-2.5">
                <span class="text-emerald-400 text-sm">✓</span>
                <span>Pendaftaran instan otomatis saat pertama kali masuk</span>
            </div>
            <div class="flex items-center gap-2.5">
                <span class="text-emerald-400 text-sm">✓</span>
                <span>Aman & terlindungi tanpa perlu mengingat password</span>
            </div>
            <div class="flex items-center gap-2.5">
                <span class="text-emerald-400 text-sm">✓</span>
                <span>Lacak status seluruh invoice & order dalam satu tempat</span>
            </div>
        </div>

        {{-- Footer notes --}}
        <p class="mt-6 text-center text-[11px] text-slate-500">
            Dengan masuk, Anda menyetujui
            <a href="{{ route('pages.terms') }}" class="text-slate-400 hover:text-slate-200 underline">Syarat & Ketentuan</a>
            serta
            <a href="{{ route('pages.privacy') }}" class="text-slate-400 hover:text-slate-200 underline">Kebijakan Privasi</a>
            kami.
        </p>
    </div>
</div>
@endsection
