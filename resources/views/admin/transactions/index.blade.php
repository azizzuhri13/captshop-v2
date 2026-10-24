@extends('layouts.admin')

@section('title', 'Transaksi')
@section('page-title', 'Transaksi')

@section('content')
    <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
        <div class="flex flex-wrap items-center gap-2">
            <a href="{{ route('admin.transactions.index') }}"
               class="rounded-full px-3 py-1.5 text-xs font-semibold transition {{ ! request('status') ? 'bg-primary text-white' : 'border border-slate-700 text-slate-300 hover:border-primary' }}">
                Semua
            </a>
            @foreach (['pending', 'paid', 'processing', 'completed', 'failed', 'expired'] as $status)
                <a href="{{ route('admin.transactions.index', ['status' => $status]) }}"
                   class="rounded-full px-3 py-1.5 text-xs font-semibold transition {{ request('status') === $status ? 'bg-primary text-white' : 'border border-slate-700 text-slate-300 hover:border-primary' }}">
                    {{ ucfirst($status) }} ({{ number_format((float) ($statusCounts[$status] ?? 0)) }})
                </a>
            @endforeach
        </div>

        <div class="flex items-center gap-2">
            <form method="GET" class="flex items-center gap-2">
                @if (request('status'))
                    <input type="hidden" name="status" value="{{ request('status') }}">
                @endif
                <input type="search" name="q" value="{{ request('q') }}" placeholder="Invoice / No. HP..." class="input-dark !w-48 !py-2">
                <button type="submit" class="rounded-lg border border-slate-700 bg-slate-800 px-4 py-2 text-sm text-slate-200 hover:border-primary">Cari</button>
            </form>

            <a href="{{ route('admin.transactions.export', request()->only(['status', 'q'])) }}" class="rounded-lg border border-secondary/50 px-4 py-2 text-sm font-medium text-secondary transition hover:bg-secondary/10">⬇ CSV</a>
        </div>
    </div>

    <div class="overflow-x-auto rounded-xl border border-slate-800 bg-slate-800/40">
        <table class="w-full text-left text-sm">
            <thead>
                <tr class="border-b border-slate-800 text-xs uppercase text-slate-500">
                    <th class="p-3">Invoice</th>
                    <th class="p-3">Produk</th>
                    <th class="p-3">Pelanggan</th>
                    <th class="p-3">Total</th>
                    <th class="p-3">Profit</th>
                    <th class="p-3">Status</th>
                    <th class="p-3">Waktu</th>
                    <th class="p-3 text-right">Aksi</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($transactions as $transaction)
                    <tr class="border-b border-slate-800/60 last:border-0">
                        <td class="p-3">
                            <a href="{{ route('admin.transactions.show', $transaction) }}" class="font-medium text-primary-light hover:text-white">{{ $transaction->invoice_id }}</a>
                        </td>
                        <td class="p-3 text-slate-300">{{ $transaction->productItem?->product?->name }} — {{ $transaction->productItem?->name }}</td>
                        <td class="p-3 text-slate-400">{{ $transaction->customer_phone }}</td>
                        <td class="p-3 font-medium text-slate-200">Rp {{ number_format((float) $transaction->total, 0, ',', '.') }}</td>
                        <td class="p-3 text-secondary">Rp {{ number_format((float) $transaction->profit, 0, ',', '.') }}</td>
                        <td class="p-3">
                            <span class="rounded-full px-2 py-0.5 text-xs font-semibold {{ $transaction->statusBadgeClass() }}">{{ $transaction->status }}</span>
                            @if ($transaction->needs_manual_review)
                                <span class="ml-1 rounded-full bg-amber-500/15 px-2 py-0.5 text-xs font-semibold text-amber-300" title="{{ $transaction->manual_review_reason }}">perlu tindakan</span>
                            @endif
                        </td>
                        <td class="p-3 text-xs text-slate-500">{{ $transaction->created_at->format('d M H:i') }}</td>
                        <td class="p-3 text-right">
                            <a href="{{ route('admin.transactions.show', $transaction) }}" class="text-xs font-medium text-primary-light hover:text-white">Detail →</a>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="8" class="p-6 text-center text-slate-500">Tidak ada transaksi.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    {{ $transactions->links() }}
@endsection
