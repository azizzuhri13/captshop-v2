@extends('layouts.app')

@section('title', 'Cek Transaksi — '.($siteName ?? 'CaptShop'))

@section('content')
    <div class="mx-auto max-w-xl space-y-6">
        <header class="text-center">
            <h1 class="font-heading text-xl font-bold text-slate-100">📋 Cek Transaksi</h1>
            <p class="mt-1 text-sm text-slate-500">Lacak status pesanan Anda dengan Invoice ID atau nomor HP.</p>
        </header>

        <form action="{{ route('track.search') }}" method="POST" class="rounded-2xl border border-slate-800 bg-slate-800/40 p-4">
            @csrf

            <label for="q" class="mb-1.5 block text-sm font-medium text-slate-300">Invoice ID atau Nomor HP</label>
            <input
                type="text"
                id="q"
                name="q"
                value="{{ $q ?? '' }}"
                placeholder="INV-20260908-XXXXXX atau 08xxxxxxxxxx"
                required
                class="input-dark"
            >

            @error('q')
                <p class="mt-1.5 text-xs text-red-400">{{ $message }}</p>
            @enderror

            <button type="submit" class="btn-primary mt-3 w-full">🔍 Cari Transaksi</button>
        </form>

        @if ($transactions !== null)
            <section class="space-y-3" aria-label="Hasil pencarian">
                <h2 class="font-heading text-sm font-bold text-slate-300">
                    Hasil untuk "<span class="text-slate-100">{{ $q }}</span>" ({{ $transactions->count() }})
                </h2>

                @forelse ($transactions as $transaction)
                    @php
                        $statusColor = match ($transaction->status) {
                            'completed', 'paid' => 'text-emerald-400',
                            'processing' => 'text-sky-400',
                            'failed', 'refunded' => 'text-red-400',
                            'expired' => 'text-slate-500',
                            default => 'text-amber-400',
                        };
                    @endphp
                    <a href="{{ route('invoice.show', $transaction->invoice_id) }}" class="block rounded-xl border border-slate-800 bg-slate-800/40 p-4 transition hover:border-primary/60">
                        <div class="flex items-center justify-between gap-3">
                            <div class="min-w-0">
                                <p class="truncate font-heading text-sm font-bold text-slate-100">{{ $transaction->invoice_id }}</p>
                                <p class="mt-0.5 truncate text-xs text-slate-500">
                                    {{ $transaction->productItem?->product?->name }} — {{ $transaction->productItem?->name }}
                                </p>
                            </div>
                            <div class="text-right">
                                <p class="font-heading text-sm font-bold text-slate-100">Rp {{ number_format((float) $transaction->total, 0, ',', '.') }}</p>
                                <p class="text-xs font-semibold uppercase {{ $statusColor }}">{{ $transaction->status }}</p>
                            </div>
                        </div>
                    </a>
                @empty
                    <p class="rounded-xl border border-dashed border-slate-700 p-6 text-center text-sm text-slate-500">
                        Tidak ada transaksi ditemukan. Periksa kembali Invoice ID / nomor HP Anda.
                    </p>
                @endforelse
            </section>
        @endif
    </div>
@endsection
