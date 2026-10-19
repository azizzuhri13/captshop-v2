@extends('layouts.app')

@section('title', 'Invoice '.$transaction->invoice_id)

@php
    $statusMeta = [
        'pending' => ['label' => 'Menunggu Pembayaran', 'class' => 'bg-amber-500/15 text-amber-400 border-amber-500/40'],
        'paid' => ['label' => 'Dibayar', 'class' => 'bg-emerald-500/15 text-emerald-400 border-emerald-500/40'],
        'processing' => ['label' => 'Diproses', 'class' => 'bg-sky-500/15 text-sky-400 border-sky-500/40'],
        'completed' => ['label' => 'Berhasil', 'class' => 'bg-emerald-500/15 text-emerald-400 border-emerald-500/40'],
        'failed' => ['label' => 'Gagal', 'class' => 'bg-red-500/15 text-red-400 border-red-500/40'],
        'expired' => ['label' => 'Kedaluwarsa', 'class' => 'bg-slate-500/15 text-slate-400 border-slate-500/40'],
        'refunded' => ['label' => 'Direfund', 'class' => 'bg-red-500/15 text-red-400 border-red-500/40'],
    ];
    $meta = $statusMeta[$transaction->status] ?? $statusMeta['pending'];
@endphp

@section('content')
    <div class="mx-auto max-w-xl space-y-6">
        <header class="text-center">
            <p class="text-xs uppercase tracking-widest text-slate-500">Invoice</p>
            <h1 class="mt-1 font-heading text-xl font-bold text-slate-100">{{ $transaction->invoice_id }}</h1>
            <span class="mt-2 inline-flex items-center gap-1.5 rounded-full border px-3 py-1 text-xs font-semibold {{ $meta['class'] }}">
                {{ $meta['label'] }}
            </span>
        </header>

        {{-- Kotak Khusus Akun Netflix, Disney+, & Canva jika Transaksi Selesai --}}
        @php
            $isNetflix = ($transaction->productItem?->product?->slug === 'netflix-premium') || $transaction->netflixAccount !== null;
            $isDisney = ($transaction->productItem?->product?->slug === 'disney-plus') || $transaction->disneyAccount !== null;
            $isCanva = ($transaction->productItem?->product?->slug === 'canva-pro') || $transaction->canvaAccount !== null;
            $isStreaming = $isNetflix || $isDisney || $isCanva;
            $streamingData = null;
            if ($isStreaming && in_array($transaction->status, ['completed'])) {
                $acc = $transaction->netflixAccount ?? $transaction->disneyAccount ?? $transaction->canvaAccount;
                if ($acc) {
                    $streamingData = [
                        'email' => $acc->email,
                        'password' => $acc->password,
                        'profile' => $acc->profile_name ?? null,
                        'pin' => $acc->pin ?? null,
                    ];
                } else {
                    $decoded = json_decode((string) $transaction->supplier_response, true);
                    if (is_array($decoded) && isset($decoded['email'])) {
                        $streamingData = $decoded;
                    }
                }
            }

            $cardBorder = 'border-red-600/60 shadow-red-600/20';
            $badgeBg = 'bg-red-600/15 border-red-600/40 text-red-500';
            $glowBg = 'bg-red-600/20';
            $iconText = 'N';
            if ($isDisney) {
                $cardBorder = 'border-sky-500/60 shadow-sky-500/20';
                $badgeBg = 'bg-sky-500/15 border-sky-500/40 text-sky-400';
                $glowBg = 'bg-sky-500/20';
                $iconText = '🏰';
            } elseif ($isCanva) {
                $cardBorder = 'border-teal-500/60 shadow-teal-500/20';
                $badgeBg = 'bg-teal-500/15 border-teal-500/40 text-teal-400';
                $glowBg = 'bg-teal-500/20';
                $iconText = '🎨';
            }
        @endphp

        @if ($isStreaming && $streamingData && in_array($transaction->status, ['completed']))
            <section class="overflow-hidden rounded-2xl border-2 {{ $cardBorder }} bg-gradient-to-b from-slate-900 via-slate-900 to-slate-950 p-5 shadow-2xl relative" x-data="{ copiedEmail: false, copiedPass: false }">
                <div class="absolute -right-12 -top-12 h-32 w-32 rounded-full {{ $glowBg }} blur-2xl pointer-events-none"></div>

                {{-- Header Card --}}
                <div class="flex items-center justify-between border-b border-slate-800 pb-4">
                    <div class="flex items-center gap-3">
                        <div class="flex h-11 w-11 items-center justify-center rounded-xl {{ $badgeBg }} border text-xl font-black">
                            {{ $iconText }}
                        </div>
                        <div>
                            <h2 class="font-heading text-base font-bold text-slate-100">{{ $transaction->productItem?->product?->name ?? 'Akun Premium' }}</h2>
                            <p class="text-xs text-slate-400">{{ $transaction->productItem?->name ?? 'Paket 1 Bulan' }}</p>
                        </div>
                    </div>
                    <span class="rounded-lg bg-emerald-500/15 border border-emerald-500/40 px-2.5 py-1 text-[11px] font-bold text-emerald-400">
                        ✓ Garansi Aktif
                    </span>
                </div>

                {{-- Account Data Box --}}
                <div class="mt-4 space-y-3">
                    {{-- Email --}}
                    <div class="rounded-xl border border-slate-800 bg-slate-950/80 p-3">
                        <span class="text-[11px] font-bold uppercase tracking-wider text-slate-400 block mb-1">Email / ID Akun</span>
                        <div class="flex items-center justify-between gap-2">
                            <code class="font-mono text-sm font-semibold text-slate-100 select-all">{{ $streamingData['email'] ?? '-' }}</code>
                            <button type="button" @click="navigator.clipboard.writeText('{{ $streamingData['email'] ?? '' }}'); copiedEmail = true; setTimeout(() => copiedEmail = false, 2000)" class="flex items-center gap-1 rounded-lg bg-slate-800 hover:bg-slate-700 border border-slate-700 px-2.5 py-1 text-xs font-medium text-slate-200 transition">
                                <span x-show="!copiedEmail">📋 Salin</span>
                                <span x-show="copiedEmail" class="text-emerald-400 font-bold" style="display: none;">✓ Tersalin</span>
                            </button>
                        </div>
                    </div>

                    {{-- Password --}}
                    <div class="rounded-xl border border-slate-800 bg-slate-950/80 p-3">
                        <span class="text-[11px] font-bold uppercase tracking-wider text-slate-400 block mb-1">Password</span>
                        <div class="flex items-center justify-between gap-2">
                            <code class="font-mono text-sm font-semibold text-slate-100 select-all">{{ $streamingData['password'] ?? '-' }}</code>
                            <button type="button" @click="navigator.clipboard.writeText('{{ $streamingData['password'] ?? '' }}'); copiedPass = true; setTimeout(() => copiedPass = false, 2000)" class="flex items-center gap-1 rounded-lg bg-slate-800 hover:bg-slate-700 border border-slate-700 px-2.5 py-1 text-xs font-medium text-slate-200 transition">
                                <span x-show="!copiedPass">📋 Salin</span>
                                <span x-show="copiedPass" class="text-emerald-400 font-bold" style="display: none;">✓ Tersalin</span>
                            </button>
                        </div>
                    </div>

                    {{-- Profile & PIN (Jika ada) --}}
                    @if (!empty($streamingData['profile']) || !empty($streamingData['pin']))
                        <div class="grid grid-cols-2 gap-3">
                            <div class="rounded-xl border border-slate-800 bg-slate-950/80 p-3">
                                <span class="text-[11px] font-bold uppercase tracking-wider text-slate-400 block mb-1">Profil Khusus</span>
                                <p class="font-mono text-sm font-bold text-amber-400">{{ $streamingData['profile'] ?? 'Profil Bebas' }}</p>
                            </div>
                            <div class="rounded-xl border border-slate-800 bg-slate-950/80 p-3">
                                <span class="text-[11px] font-bold uppercase tracking-wider text-slate-400 block mb-1">PIN Profil</span>
                                <p class="font-mono text-sm font-bold text-amber-400">{{ $streamingData['pin'] ?? 'Tanpa PIN' }}</p>
                            </div>
                        </div>
                    @endif
                </div>

                {{-- Action Buttons --}}
                <div class="mt-4 pt-4 border-t border-slate-800/80 flex flex-col sm:flex-row gap-2.5">
                    @if ($isCanva)
                        <a href="https://www.canva.com/login" target="_blank" rel="noopener" class="flex-1 inline-flex items-center justify-center gap-2 rounded-xl bg-teal-600 hover:bg-teal-700 py-2.5 px-4 text-xs font-bold text-white shadow-lg shadow-teal-600/30 transition">
                            🎨 Buka Canva & Login
                        </a>
                    @elseif ($isDisney)
                        <a href="https://www.hotstar.com/id" target="_blank" rel="noopener" class="flex-1 inline-flex items-center justify-center gap-2 rounded-xl bg-sky-600 hover:bg-sky-700 py-2.5 px-4 text-xs font-bold text-white shadow-lg shadow-sky-600/30 transition">
                            🏰 Buka Disney+ & Login
                        </a>
                    @else
                        <a href="https://www.netflix.com/login" target="_blank" rel="noopener" class="flex-1 inline-flex items-center justify-center gap-2 rounded-xl bg-red-600 hover:bg-red-700 py-2.5 px-4 text-xs font-bold text-white shadow-lg shadow-red-600/30 transition">
                            🎬 Buka Netflix & Login
                        </a>
                    @endif

                    @if (!$isCanva)
                        <a href="/admin/" target="_blank" rel="noopener" class="inline-flex items-center justify-center gap-2 rounded-xl border border-slate-700 bg-slate-800/80 hover:bg-slate-700 py-2.5 px-4 text-xs font-semibold text-slate-200 transition">
                            🔐 Ambil Kode OTP / Masuk
                        </a>
                    @endif
                </div>

                {{-- Notice / Rules --}}
                <div class="mt-4 rounded-xl border border-amber-500/20 bg-amber-500/5 p-3 text-xs text-amber-300/90 leading-relaxed">
                    <strong class="font-bold text-amber-300">⚠️ Aturan Pemakaian:</strong>
                    <ul class="list-disc list-inside mt-1 space-y-0.5 text-slate-400">
                        <li>Gunakan akun sesuai peruntukannya.</li>
                        <li>Dilarang mengubah data akun atau password tanpa izin admin.</li>
                        @if (!$isCanva)
                            <li>Jika diminta kode login/OTP saat masuk, klik tombol <strong>"Ambil Kode OTP / Masuk"</strong> di atas.</li>
                        @endif
                    </ul>
                </div>
            </section>
        @endif

        {{-- Kotak Peringatan Stok Habis & Hubungi Admin WhatsApp jika status processing/paid dan akun belum dialokasikan --}}
        @if ($isStreaming && in_array($transaction->status, ['processing', 'paid']) && !$streamingData)
            @php
                $waNumber = '6282260677717';
                $waText = "Halo Admin CAPTSHOP, saya sudah bayar pesanan dengan nomor Invoice {$transaction->invoice_id}. Keterangannya stok akun sedang habis/disiapkan. Mohon dibantu kirim akun ya min.";
                $waUrl = "https://wa.me/{$waNumber}?text=" . rawurlencode($waText);
            @endphp
            <section class="overflow-hidden rounded-2xl border-2 border-amber-500/50 bg-gradient-to-b from-amber-950/20 via-slate-900 to-slate-950 p-5 shadow-2xl shadow-amber-500/10 relative">
                <div class="absolute -right-10 -top-10 h-28 w-28 rounded-full bg-amber-500/15 blur-2xl pointer-events-none"></div>

                <div class="flex items-start gap-3.5">
                    <div class="flex h-12 w-12 shrink-0 items-center justify-center rounded-2xl bg-amber-500/15 border border-amber-500/40 text-2xl text-amber-400">
                        ⏳
                    </div>
                    <div>
                        <div class="flex items-center gap-2">
                            <span class="rounded bg-amber-500/20 px-2 py-0.5 text-[10px] font-bold text-amber-300 tracking-wide uppercase">Antrean Restock</span>
                            <span class="text-xs text-slate-400">{{ $transaction->productItem?->product?->name ?? 'Netflix Premium' }}</span>
                        </div>
                        <h2 class="font-heading text-base font-bold text-slate-100 mt-1">Pembayaran Diterima — Menunggu Stok Akun</h2>
                        <p class="text-xs text-slate-300/90 mt-1.5 leading-relaxed">
                            Pembayaran Anda sebesar <strong class="text-emerald-400">Rp {{ number_format((float) $transaction->total, 0, ',', '.') }}</strong> sudah terverifikasi lunas! Saat ini stok akun sedang habis dan dalam proses penyiapan oleh admin.
                        </p>
                    </div>
                </div>

                <div class="mt-5 rounded-xl border border-slate-800 bg-slate-950/80 p-4 space-y-3">
                    <p class="text-xs text-slate-300">
                        Silakan hubungi WhatsApp Admin sekarang agar akun segera dikirimkan ke Anda:
                    </p>

                    <a href="{{ $waUrl }}" target="_blank" rel="noopener" class="flex w-full items-center justify-center gap-2.5 rounded-xl bg-emerald-600 hover:bg-emerald-500 py-3 px-4 font-heading text-sm font-bold text-white shadow-lg shadow-emerald-600/30 transition transform active:scale-98">
                        <svg class="h-5 w-5 fill-current" viewBox="0 0 24 24">
                            <path d="M.057 24l1.687-6.163c-1.041-1.804-1.588-3.849-1.587-5.946.003-6.556 5.338-11.891 11.893-11.891 3.181.001 6.167 1.24 8.413 3.488 2.245 2.248 3.481 5.236 3.48 8.414-.003 6.557-5.338 11.892-11.893 11.892-1.99-.001-3.951-.5-5.688-1.448l-6.305 1.654zm6.597-3.807c1.676.995 3.276 1.591 5.392 1.592 5.448 0 9.886-4.434 9.889-9.885.002-5.462-4.415-9.89-9.881-9.892-5.452 0-9.887 4.434-9.889 9.884-.001 2.225.651 3.891 1.746 5.634l-.999 3.648 3.742-.981z"/>
                        </svg>
                        <span>Hubungi WhatsApp Admin</span>
                    </a>

                    <div class="rounded-lg bg-slate-900 border border-slate-800 p-2.5 text-[11px] text-slate-400">
                        <span class="text-slate-500 font-bold block mb-1">Preview Format Pesan Otomatis:</span>
                        <span class="font-mono text-slate-300 italic">"Halo Admin CAPTSHOP, saya sudah bayar pesanan dengan nomor Invoice {{ $transaction->invoice_id }}. Keterangannya stok akun sedang habis/disiapkan. Mohon dibantu kirim akun ya min."</span>
                    </div>
                </div>
            </section>
        @endif

        {{-- Banner jika status kedaluwarsa --}}
        @if ($transaction->status === 'expired')
            <div class="rounded-2xl border border-red-500/30 bg-red-950/20 p-5 text-center">
                <div class="inline-flex h-12 w-12 items-center justify-center rounded-xl bg-red-500/10 text-2xl text-red-400 mb-2">
                    ⌛
                </div>
                <h2 class="font-heading text-base font-bold text-red-400 sm:text-lg">
                    Waktu Pembayaran Telah Habis
                </h2>
                <p class="mt-1 text-xs sm:text-sm text-slate-400 max-w-md mx-auto">
                    Batas waktu penyelesaian pembayaran telah berakhir. Pesanan ini telah dibatalkan secara otomatis oleh sistem.
                </p>

                @php
                    $product = $transaction->productItem?->product;
                    $category = $product?->category;
                @endphp
                <div class="mt-4">
                    @if ($product && $category)
                        <a href="{{ route('product.show', [$category->slug, $product->slug]) }}" class="inline-flex items-center justify-center gap-2 rounded-xl bg-primary px-5 py-2.5 text-xs font-semibold text-white transition hover:bg-primary-dark">
                            <span>🔄</span>
                            <span>Pesan Ulang Produk Ini</span>
                        </a>
                    @else
                        <a href="{{ route('home') }}" class="inline-flex items-center justify-center gap-2 rounded-xl bg-primary px-5 py-2.5 text-xs font-semibold text-white transition hover:bg-primary-dark">
                            <span>🏠</span>
                            <span>Kembali ke Beranda</span>
                        </a>
                    @endif
                </div>
            </div>
        @endif

        {{-- Countdown batas pembayaran --}}
        @if ($transaction->status === 'pending' && $transaction->expired_at)
            <div
                x-data="{
                    deadline: new Date('{{ $transaction->expired_at->toIso8601String() }}').getTime(),
                    remaining: 0,
                    reloaded: false,
                    init() {
                        this.tick();
                        setInterval(() => this.tick(), 1000);
                    },
                    tick() {
                        this.remaining = Math.max(0, Math.floor((this.deadline - Date.now()) / 1000));
                        if (this.remaining <= 0 && !this.reloaded) {
                            this.reloaded = true;
                            setTimeout(() => window.location.reload(), 1500);
                        }
                    },
                }"
                class="rounded-2xl border border-amber-500/30 bg-amber-500/10 p-4 text-center"
            >
                <p class="text-xs text-amber-400/80">Selesaikan pembayaran dalam</p>
                <p class="mt-1 font-mono text-2xl font-bold text-amber-400" x-text="Math.floor(remaining / 3600) + ':' + String(Math.floor((remaining % 3600) / 60)).padStart(2, '0') + ':' + String(remaining % 60).padStart(2, '0')">
                    {{ $transaction->expired_at->diffForHumans() }}
                </p>
            </div>
        @endif

        {{-- Instruksi pembayaran --}}
        @if ($transaction->status === 'pending' && $transaction->payment_gateway === 'belibayar')
            <section class="rounded-2xl border border-slate-800 bg-slate-800/40 p-4">
                <h2 class="mb-3 font-heading text-sm font-bold text-slate-100">Instruksi Pembayaran</h2>

                <p class="text-sm text-slate-400">
                    Metode: <span class="font-semibold text-slate-200">{{ $transaction->payment_method }} — {{ $transaction->payment_channel }}</span>
                </p>

                @php
                    $instructions = $transaction->payment_instructions ?? [];
                @endphp
                @if ($transaction->payment_gateway === 'belibayar' && !empty($instructions['va_number']))
                    <div class="mt-3 rounded-lg border border-slate-700 bg-slate-900 p-4">
                        <p class="text-xs text-slate-400">{{ in_array($transaction->payment_channel, ['ALFAMART', 'INDOMARET']) ? 'Kode bayar di kasir' : 'Nomor Virtual Account' }}</p>
                        <p class="mt-1 break-all font-mono text-lg font-bold text-white">{{ $instructions['va_number'] }}</p>
                    </div>
                @elseif ($transaction->payment_gateway === 'belibayar' && !empty($instructions['qr_url']))
                    @php
                        $qrSource = $instructions['qr_url'];
                        $isSandboxDummy = ($qrSource === 'data:image/svg+xml;base64,PHN2Zy8+');
                        if ($isSandboxDummy) {
                            $qrSource = 'https://api.qrserver.com/v1/create-qr-code/?size=300x300&data=BELIBAYAR-SANDBOX-' . $transaction->invoice_id;
                        }
                    @endphp
                    <div class="mt-3 rounded-lg bg-white p-4 text-center">
                        <img src="{{ $qrSource }}" alt="QRIS untuk invoice {{ $transaction->invoice_id }}" class="mx-auto h-56 w-56 object-contain">
                        <p class="mt-2 text-xs text-slate-700">Pindai QR dengan aplikasi pembayaran Anda</p>
                        @if ($isSandboxDummy)
                            <span class="mt-2 inline-block rounded-md bg-amber-100 px-2 py-0.5 text-[10px] font-bold text-amber-800">
                                ⚠️ Mode Sandbox (QR Simulasi Belibayar)
                            </span>
                        @endif
                    </div>
                @elseif ($transaction->payment_gateway === 'belibayar' && $transaction->payment_channel === 'OVO' && $transaction->payment_ref)
                    <p class="mt-3 rounded-lg border border-slate-700 p-3 text-sm text-slate-200">Cek notifikasi pembayaran di aplikasi OVO pada nomor yang Anda masukkan.</p>
                @elseif ($transaction->payment_url)
                    <a href="{{ $transaction->payment_url }}" target="_blank" rel="noopener" class="btn-primary mt-3 w-full">
                        Bayar via {{ $transaction->payment_channel }} →
                    </a>
                @else
                    <p class="mt-3 rounded-lg border border-dashed border-slate-700 p-3 text-xs text-slate-500">
                        Instruksi pembayaran belum tersedia. Jangan transfer sebelum mendapat instruksi resmi. Hubungi CS kami.
                    </p>
                @endif

                <form action="{{ route('invoice.check-status', $transaction->invoice_id) }}" method="POST" class="mt-3">
                    @csrf
                    <button type="submit" class="w-full rounded-lg border border-slate-700 bg-slate-800 px-4 py-2.5 text-sm font-medium text-slate-200 transition hover:border-primary hover:text-white">
                        🔄 Cek Status Pembayaran
                    </button>
                </form>
            </section>
        @endif

        {{-- Rincian pesanan --}}
        <section class="rounded-2xl border border-slate-800 bg-slate-800/40 p-4">
            <h2 class="mb-3 font-heading text-sm font-bold text-slate-100">Rincian Pesanan</h2>

            <dl class="space-y-2 text-sm">
                <div class="flex justify-between gap-4">
                    <dt class="text-slate-500">Produk</dt>
                    <dd class="text-right font-medium text-slate-200">{{ $transaction->productItem?->product?->name }}</dd>
                </div>
                <div class="flex justify-between gap-4">
                    <dt class="text-slate-500">Item</dt>
                    <dd class="text-right font-medium text-slate-200">{{ $transaction->productItem?->name }}</dd>
                </div>
                @foreach ($transaction->customer_data ?? [] as $label => $value)
                    <div class="flex justify-between gap-4">
                        <dt class="text-slate-500">{{ ucfirst(str_replace('_', ' ', $label)) }}</dt>
                        <dd class="text-right font-medium text-slate-200">{{ $value }}</dd>
                    </div>
                @endforeach
                <div class="flex justify-between gap-4">
                    <dt class="text-slate-500">No. WhatsApp</dt>
                    @php($rawPhone = (string) $transaction->customer_phone)
                    <dd class="text-right font-medium text-slate-200">
                        {{ strlen($rawPhone) > 7 ? substr($rawPhone, 0, 4).'****'.substr($rawPhone, -3) : $rawPhone }}
                    </dd>
                </div>
                <div class="flex justify-between gap-4">
                    <dt class="text-slate-500">Harga</dt>
                    <dd class="text-right font-medium text-slate-200">Rp {{ number_format((float) $transaction->amount, 0, ',', '.') }}</dd>
                </div>
                @if ((float) $transaction->coupon_discount > 0)
                    <div class="flex justify-between gap-4">
                        <dt class="text-emerald-400">Diskon ({{ $transaction->coupon_code }})</dt>
                        <dd class="text-right font-medium text-emerald-400">-Rp {{ number_format((float) $transaction->coupon_discount, 0, ',', '.') }}</dd>
                    </div>
                @endif
                <div class="flex justify-between gap-4">
                    <dt class="text-slate-500">Biaya layanan</dt>
                    <dd class="text-right font-medium text-slate-200">Rp {{ number_format((float) $transaction->fee, 0, ',', '.') }}</dd>
                </div>
                <div class="flex justify-between gap-4 border-t border-slate-800 pt-2 font-heading text-base font-bold">
                    <dt>Total</dt>
                    <dd class="text-secondary">Rp {{ number_format((float) $transaction->total, 0, ',', '.') }}</dd>
                </div>
            </dl>

            @if ($transaction->supplier_sn && in_array($transaction->status, ['completed']))
                <div class="mt-4 rounded-lg border border-emerald-500/30 bg-emerald-500/10 p-3">
                    <p class="text-xs font-semibold text-emerald-400">SN / Bukti Transaksi</p>
                    <p class="mt-1 break-all font-mono text-sm text-emerald-300">{{ $transaction->supplier_sn }}</p>
                </div>
            @endif
        </section>

        <p class="text-center text-xs text-slate-500">
            Simpan ID invoice ini untuk mengecek status pesanan Anda kapan saja
            tanpa perlu login.
        </p>
    </div>
@endsection
