@extends('layouts.app')

@section('title', 'Tentang Kami — '.($siteName ?? 'CaptShop'))

@section('content')
    <div class="mx-auto max-w-xl space-y-4">
        <header>
            <h1 class="font-heading text-xl font-bold text-slate-100">Tentang {{ $siteName ?? 'CaptShop' }}</h1>
        </header>

        <div class="space-y-4 rounded-2xl border border-slate-800 bg-slate-800/40 p-5 text-sm leading-relaxed text-slate-400">
            <p>
                {{ $siteName ?? 'CaptShop' }} adalah platform top-up game, voucher digital, dan pembayaran PPOB
                yang berdiri untuk memudahkan Anda membeli produk digital favorit dengan cepat, aman,
                dan tanpa perlu registrasi akun.
            </p>
            <p>
                Kami terhubung langsung ke supplier resmi dan payment gateway terpercaya, sehingga setiap
                pesanan diproses otomatis — sebagian besar selesai dalam hitungan detik setelah pembayaran
                dikonfirmasi.
            </p>
            <p>
                Ribuan pemain sudah mempercayakan transaksi mereka kepada kami. Bergabunglah dan rasakan
                pengalaman top-up paling menyenangkan!
            </p>
        </div>

        <x-trust-section :site-name="$siteName ?? null" />
    </div>
@endsection
