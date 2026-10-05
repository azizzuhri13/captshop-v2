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

        {{-- Countdown batas pembayaran --}}
        @if ($transaction->status === 'pending' && $transaction->expired_at)
            <div
                x-data="{
                    deadline: new Date('{{ $transaction->expired_at->toIso8601String() }}').getTime(),
                    remaining: 0,
                    init() {
                        this.tick();
                        setInterval(() => this.tick(), 1000);
                    },
                    tick() {
                        this.remaining = Math.max(0, Math.floor((this.deadline - Date.now()) / 1000));
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
        @if ($transaction->status === 'pending')
            <section class="rounded-2xl border border-slate-800 bg-slate-800/40 p-4">
                <h2 class="mb-3 font-heading text-sm font-bold text-slate-100">Instruksi Pembayaran</h2>

                <p class="text-sm text-slate-400">
                    Metode: <span class="font-semibold text-slate-200">{{ $transaction->payment_method }} — {{ $transaction->payment_channel }}</span>
                </p>

                @if ($transaction->payment_url)
                    <a href="{{ $transaction->payment_url }}" target="_blank" rel="noopener" class="btn-primary mt-3 w-full">
                        Bayar via {{ $transaction->payment_channel }} →
                    </a>
                @else
                    <p class="mt-3 rounded-lg border border-dashed border-slate-700 p-3 text-xs text-slate-500">
                        Instruksi pembayaran sedang disiapkan. Jika tombol tidak muncul dalam beberapa saat, hubungi CS kami.
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
                    <dd class="text-right font-medium text-slate-200">{{ $transaction->customer_phone }}</dd>
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
