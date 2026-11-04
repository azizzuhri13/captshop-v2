@extends('layouts.app')

@section('title', 'Kontak Customer Service — '.($siteName ?? 'CAPTSHOP'))
@section('meta_description', 'Hubungi customer service CAPTSHOP via WhatsApp atau Instagram. CS aktif 24/7 untuk bantuan pesanan, pembayaran, dan refund.')

@section('content')
    <div class="mx-auto max-w-xl space-y-4">
        <header>
            <h1 class="font-heading text-xl font-bold text-slate-100">💬 Kontak Customer Service</h1>
            <p class="mt-1 text-sm text-slate-400">
                Tim CS CAPTSHOP siap membantu setiap hari, 24 jam. Sertakan Invoice ID atau nomor HP
                yang dipakai saat order agar penanganan lebih cepat.
            </p>
        </header>

        @include('partials.support-contacts')

        <div class="space-y-3 rounded-2xl border border-slate-800 bg-slate-900 p-4 text-sm text-slate-400">
            <div>
                <h2 class="font-semibold text-slate-200">Jam operasional</h2>
                <p class="mt-1">Customer service: 24 jam / 7 hari (termasuk hari libur).</p>
            </div>
            <div>
                <h2 class="font-semibold text-slate-200">Yang perlu disiapkan</h2>
                <ul class="mt-1 list-inside list-disc space-y-1">
                    <li>Invoice ID (contoh: INV-XXXXXXXX) atau nomor HP saat order.</li>
                    <li>Bukti pembayaran bila status transaksi belum berubah.</li>
                    <li>Tangkapan layar pesan error bila ada kendala saat checkout.</li>
                </ul>
            </div>
            <div>
                <h2 class="font-semibold text-slate-200">Pesanan bermasalah</h2>
                <p class="mt-1">
                    Bila pesanan gagal setelah pembayaran terkonfirmasi, dana dikembalikan
                    (refund) atau pesanan diproses ulang sesuai
                    <a href="{{ route('pages.terms') }}" class="underline decoration-slate-600 underline-offset-2 hover:text-slate-200">Syarat &amp; Ketentuan</a>.
                </p>
            </div>
        </div>

        <p class="text-xs text-slate-500">
            Status transaksi juga bisa dicek mandiri di
            <a href="{{ route('track.index') }}" class="underline decoration-slate-600 underline-offset-2 hover:text-slate-300">Cek Transaksi</a>.
        </p>
    </div>
@endsection