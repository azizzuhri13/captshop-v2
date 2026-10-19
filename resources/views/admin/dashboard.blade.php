@extends('layouts.admin')

@section('title', 'Dashboard')
@section('page-title', 'Dashboard')

@section('content')
    @php
        $revToday = (float) $today->revenue;
        $profToday = (float) $today->profit;
        $cntToday = (int) $today->total_count;
        $pendToday = (int) $today->pending_count;
        $failToday = (int) $today->failed_count;
    @endphp

    {{-- Stat cards --}}
    <div class="grid grid-cols-2 gap-3 lg:grid-cols-4">
        <div class="rounded-xl border border-slate-800/80 bg-slate-900/40 p-4">
            <p class="text-xs font-medium text-slate-400">Transaksi Hari Ini</p>
            <p class="mt-1 font-mono text-2xl font-bold {{ $cntToday > 0 ? 'text-white' : 'text-slate-400' }}">{{ number_format($cntToday) }}</p>
        </div>

        <div class="rounded-xl border border-slate-800/80 bg-slate-900/40 p-4">
            <p class="text-xs font-medium text-slate-400">Revenue Hari Ini</p>
            <p class="mt-1 font-mono text-2xl font-bold {{ $revToday > 0 ? 'text-emerald-400' : 'text-slate-400' }}">Rp {{ number_format($revToday, 0, ',', '.') }}</p>
        </div>

        <div class="rounded-xl border border-slate-800/80 bg-slate-900/40 p-4">
            <p class="text-xs font-medium text-slate-400">Profit Hari Ini</p>
            <p class="mt-1 font-mono text-2xl font-bold {{ $profToday > 0 ? 'text-emerald-400' : 'text-slate-400' }}">Rp {{ number_format($profToday, 0, ',', '.') }}</p>
        </div>

        <div class="rounded-xl border border-slate-800/80 bg-slate-900/40 p-4">
            <p class="text-xs font-medium text-slate-400">Pending / Gagal</p>
            <p class="mt-1 font-mono text-2xl font-bold">
                <span class="{{ $pendToday > 0 ? 'text-amber-400' : 'text-slate-400' }}">{{ number_format($pendToday) }}</span>
                <span class="text-slate-600">/</span>
                <span class="{{ $failToday > 0 ? 'text-rose-400' : 'text-slate-400' }}">{{ number_format($failToday) }}</span>
            </p>
        </div>
    </div>

    {{-- Ringkasan bulan ini --}}
    <div class="mt-3 rounded-xl border border-slate-800/80 bg-gradient-to-r from-slate-900/60 to-slate-900/30 p-4">
        <div class="flex items-center justify-between">
            <div>
                <p class="text-xs font-medium text-slate-400">Bulan {{ now()->translatedFormat('F Y') }}</p>
                <p class="mt-0.5 text-sm text-slate-300 font-semibold">{{ number_format((float) $month->total_count) }} transaksi</p>
            </div>
            <div class="text-right">
                <p class="font-mono text-xl font-bold text-white">Rp {{ number_format((float) $month->revenue, 0, ',', '.') }}</p>
                @if((float) $month->revenue > 0)
                    <span class="text-[10px] text-emerald-400 font-medium bg-emerald-500/10 px-2 py-0.5 rounded-full border border-emerald-500/20">Lunas</span>
                @endif
            </div>
        </div>
    </div>

    <div class="mt-6 grid gap-6 lg:grid-cols-5">
        {{-- Grafik tren 7 hari --}}
        <div class="rounded-xl border border-slate-800 bg-slate-800/40 p-4 lg:col-span-3">
            <h2 class="mb-4 font-heading text-sm font-bold text-slate-200">Tren 7 Hari Terakhir</h2>

            <div class="flex h-40 items-end gap-2">
                @foreach ($trendSeries as $point)
                    @php
                        $maxCount = max(1, $trendSeries->max('count'));
                        $height = ($point['count'] / $maxCount) * 100;
                    @endphp
                    <div class="flex flex-1 flex-col items-center gap-1">
                        <span class="text-[10px] text-slate-500">{{ $point['count'] ?: '' }}</span>
                        <div
                            class="w-full rounded-t-md bg-gradient-to-t from-primary-dark to-primary {{ $point['count'] === 0 ? 'opacity-20' : '' }}"
                            style="height: {{ max(4, $height) }}%"
                            title="{{ $point['date'] }}: {{ $point['count'] }} transaksi (Rp {{ number_format($point['revenue'], 0, ',', '.') }})"
                        ></div>
                        <span class="text-[10px] text-slate-500">{{ $point['date'] }}</span>
                    </div>
                @endforeach
            </div>
        </div>

        {{-- Top produk --}}
        <div class="rounded-xl border border-slate-800 bg-slate-800/40 p-4 lg:col-span-2">
            <h2 class="mb-4 font-heading text-sm font-bold text-slate-200">🏆 Top Produk</h2>

            @forelse ($topProducts as $row)
                <div class="flex items-center justify-between gap-2 border-b border-slate-800 py-2 last:border-0">
                    <div class="min-w-0">
                        <p class="truncate text-sm font-medium text-slate-200">{{ $row->productItem?->product?->name }}</p>
                        <p class="truncate text-xs text-slate-500">{{ $row->productItem?->name }}</p>
                    </div>
                    <div class="shrink-0 text-right">
                        <p class="text-sm font-bold text-slate-100">{{ number_format((float) $row->total) }}x</p>
                        <p class="text-xs text-secondary">Rp {{ number_format((float) $row->revenue, 0, ',', '.') }}</p>
                    </div>
                </div>
            @empty
                <p class="text-sm text-slate-500">Belum ada transaksi.</p>
            @endforelse
        </div>
    </div>

    {{-- Transaksi terbaru --}}
    <div class="mt-6 rounded-xl border border-slate-800 bg-slate-800/40 p-4">
        <div class="mb-4 flex items-center justify-between">
            <h2 class="font-heading text-sm font-bold text-slate-200">Transaksi Terbaru</h2>
            <a href="{{ route('admin.transactions.index') }}" class="text-xs font-medium text-primary-light hover:text-white">Lihat semua →</a>
        </div>

        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm">
                <thead>
                    <tr class="border-b border-slate-800 text-xs uppercase text-slate-500">
                        <th class="pb-2 pr-4">Invoice</th>
                        <th class="pb-2 pr-4">Produk</th>
                        <th class="pb-2 pr-4">Total</th>
                        <th class="pb-2 pr-4">Status</th>
                        <th class="pb-2">Waktu</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($recentTransactions as $transaction)
                        <tr class="border-b border-slate-800/60 last:border-0">
                            <td class="py-2 pr-4">
                                <a href="{{ route('admin.transactions.show', $transaction) }}" class="font-medium text-primary-light hover:text-white">{{ $transaction->invoice_id }}</a>
                            </td>
                            <td class="py-2 pr-4 text-slate-300">{{ $transaction->productItem?->product?->name }} — {{ $transaction->productItem?->name }}</td>
                            <td class="py-2 pr-4 font-medium text-slate-200">Rp {{ number_format((float) $transaction->total, 0, ',', '.') }}</td>
                            <td class="py-2 pr-4">
                                <span class="rounded-full px-2 py-0.5 text-xs font-semibold {{ $transaction->statusBadgeClass() }}">
                                    {{ $transaction->status }}
                                </span>
                            </td>
                            <td class="py-2 text-xs text-slate-500">{{ $transaction->created_at->format('d M H:i') }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="py-4 text-center text-sm text-slate-500">Belum ada transaksi.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
@endsection
