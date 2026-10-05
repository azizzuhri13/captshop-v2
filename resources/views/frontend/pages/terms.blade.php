@extends('layouts.app')

@section('title', 'Syarat & Ketentuan — '.($siteName ?? 'CaptShop'))

@section('content')
    <div class="mx-auto max-w-xl space-y-4">
        <header>
            <h1 class="font-heading text-xl font-bold text-slate-100">Syarat & Ketentuan</h1>
            <p class="mt-1 text-xs text-slate-500">Terakhir diperbarui: {{ now()->translatedFormat('d F Y') }}</p>
        </header>

        <div class="space-y-4 rounded-2xl border border-slate-800 bg-slate-800/40 p-5 text-sm leading-relaxed text-slate-400">
            <section>
                <h2 class="font-semibold text-slate-200">1. Ketentuan Umum</h2>
                <p class="mt-1">Dengan menggunakan layanan {{ $siteName ?? 'CaptShop' }}, Anda menyetujui seluruh syarat dan ketentuan yang berlaku. Kami dapat memperbarui ketentuan ini sewaktu-waktu.</p>
            </section>

            <section>
                <h2 class="font-semibold text-slate-200">2. Pesanan & Pembayaran</h2>
                <ul class="mt-1 list-inside list-disc space-y-1">
                    <li>Pastikan data akun game (User ID, Zone ID, dll) yang Anda masukkan benar. Pesanan yang salah karena kelalaian input tidak dapat direfund.</li>
                    <li>Pesanan wajib dibayar sebelum batas waktu yang tertera pada invoice. Pesanan yang melewati batas waktu akan otomatis hangus.</li>
                    <li>Harga dapat berubah sewaktu-waktu mengikuti harga pasar dan supplier.</li>
                </ul>
            </section>

            <section>
                <h2 class="font-semibold text-slate-200">3. Refund</h2>
                <ul class="mt-1 list-inside list-disc space-y-1">
                    <li>Refund penuh diberikan jika pesanan gagal diproses oleh sistem/supplier.</li>
                    <li>Refund tidak dapat diberikan untuk kesalahan input data oleh pembeli.</li>
                    <li>Proses refund maksimal 3 hari kerja setelah diverifikasi tim kami.</li>
                </ul>
            </section>

            <section>
                <h2 class="font-semibold text-slate-200">4. Larangan</h2>
                <p class="mt-1">Anda tidak diperkenankan menggunakan layanan kami untuk aktivitas yang melanggar hukum, penipuan, atau tindakan yang merugikan pihak lain. Kami berhak menolak/membatalkan pesanan yang mencurigakan.</p>
            </section>
        </div>
    </div>
@endsection
