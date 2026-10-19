@extends('layouts.app')

@section('title', 'Kebijakan Privasi — '.($siteName ?? 'CAPTSHOP'))

@section('content')
    <div class="mx-auto max-w-xl space-y-4">
        <header>
            <h1 class="font-heading text-xl font-bold text-slate-100">Kebijakan Privasi</h1>
            <p class="mt-1 text-xs text-slate-500">Terakhir diperbarui: {{ now()->translatedFormat('d F Y') }}</p>
        </header>

        <div class="space-y-4 rounded-2xl border border-slate-800 bg-slate-800/40 p-5 text-sm leading-relaxed text-slate-400">
            <section>
                <h2 class="font-semibold text-slate-200">Data yang Kami Kumpulkan</h2>
                <ul class="mt-1 list-inside list-disc space-y-1">
                    <li>Data pesanan: data akun game (User ID, Zone ID), item yang dibeli, dan nilai transaksi.</li>
                    <li>Data kontak: nomor WhatsApp dan (opsional) email — untuk pengiriman invoice dan notifikasi status.</li>
                    <li>Data teknis: alamat IP dan log transaksi untuk keamanan sistem.</li>
                </ul>
            </section>

            <section>
                <h2 class="font-semibold text-slate-200">Penggunaan Data</h2>
                <p class="mt-1">Data hanya digunakan untuk memproses dan memvalidasi pesanan Anda, mengirim notifikasi status, serta keperluan audit keamanan. Kami tidak menjual atau membagikan data Anda kepada pihak ketiga di luar partner pemrosesan (payment gateway &amp; supplier resmi).</p>
            </section>

            <section>
                <h2 class="font-semibold text-slate-200">Penggunaan Data Akun Google (Google OAuth)</h2>
                <p class="mt-1">Ketika Anda masuk menggunakan layanan Google Sign-In, kami hanya mengakses data profil dasar Anda (nama, alamat email, dan foto profil publik) semata-mata untuk autentikasi dan pembuatan akun di platform {{ $siteName ?? 'CAPTSHOP' }}. Kami tidak mengakses, meminta, atau menyimpan kata sandi akun Google Anda, dan kami tidak membagikan data tersebut kepada pihak lain.</p>
            </section>

            <section>
                <h2 class="font-semibold text-slate-200">Keamanan</h2>
                <p class="mt-1">Kami menerapkan enkripsi transport (HTTPS), verifikasi signature webhook, dan pembatasan akses internal. Data sensitif seperti kredensial admin disimpan terenkripsi/di-hash.</p>
            </section>

            <section>
                <h2 class="font-semibold text-slate-200">Hak Anda</h2>
                <p class="mt-1">Anda dapat meminta penghapusan data transaksi (di luar kewajiban retensi pembukuan) dengan menghubungi CS kami, dengan menyertakan Invoice ID terkait.</p>
            </section>
        </div>
    </div>
@endsection
