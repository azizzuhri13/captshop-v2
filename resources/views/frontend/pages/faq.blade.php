@extends('layouts.app')

@section('title', 'FAQ — '.($siteName ?? 'CaptShop'))

@section('content')
    <div class="mx-auto max-w-xl space-y-4">
        <header>
            <h1 class="font-heading text-xl font-bold text-slate-100">❓ Pusat Bantuan (FAQ)</h1>
        </header>

        <div class="space-y-3">
            @foreach ([
                ['q' => 'Apakah harus daftar akun dulu?', 'a' => 'Tidak perlu! Cukup pilih produk, isi data akun game Anda, lakukan pembayaran, dan pesanan langsung diproses.'],
                ['q' => 'Berapa lama proses top-up?', 'a' => 'Sebagian besar pesanan diproses otomatis kurang dari 1 menit setelah pembayaran terkonfirmasi. Beberapa produk tertentu bisa lebih lama tergantung supplier.'],
                ['q' => 'Metode pembayaran apa saja yang tersedia?', 'a' => 'Virtual Account (BCA, BNI, BRI, Mandiri, dll), E-Wallet (DANA, OVO, GoPay, ShopeePay), Retail (Alfamart, Indomaret), dan QRIS.'],
                ['q' => 'Bagaimana cara cek status pesanan?', 'a' => 'Gunakan halaman Cek Transaksi dengan memasukkan Invoice ID atau nomor HP yang Anda gunakan saat order.'],
                ['q' => 'Pesanan saya gagal/bermasalah, bagaimana?', 'a' => 'Hubungi CS kami via WhatsApp 24/7 dengan menyertakan Invoice ID. Dana akan diproses refund/penggantian sesuai kebijakan.'],
                ['q' => 'Apakah data saya aman?', 'a' => 'Kami hanya menyimpan data yang diperlukan untuk memproses pesanan (data akun game dan kontak). Data tidak dibagikan ke pihak ketiga.'],
            ] as $item)
                <details class="group rounded-xl border border-slate-800 bg-slate-800/40 p-4">
                    <summary class="flex cursor-pointer items-center justify-between gap-2 text-sm font-semibold text-slate-200">
                        {{ $item['q'] }}
                        <span class="text-slate-500 transition group-open:rotate-45">＋</span>
                    </summary>
                    <p class="mt-3 text-sm leading-relaxed text-slate-400">{{ $item['a'] }}</p>
                </details>
            @endforeach
        </div>
    </div>
@endsection
