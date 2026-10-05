@extends('layouts.admin')

@section('title', 'Produk')
@section('page-title', 'Produk')

@section('content')
    <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
        <form method="GET" class="flex items-center gap-2">
            <input type="search" name="q" value="{{ request('q') }}" placeholder="Cari produk..." class="input-dark !w-64 !py-2">
            <button type="submit" class="rounded-lg border border-slate-700 bg-slate-800 px-4 py-2 text-sm text-slate-200 hover:border-primary">Cari</button>
        </form>

        <a href="{{ route('admin.products.create') }}" class="btn-primary !py-2 text-sm">+ Produk Baru</a>
    </div>

    <div class="overflow-x-auto rounded-xl border border-slate-800 bg-slate-800/40">
        <table class="w-full text-left text-sm">
            <thead>
                <tr class="border-b border-slate-800 text-xs uppercase text-slate-500">
                    <th class="p-3">Produk</th>
                    <th class="p-3">Kategori</th>
                    <th class="p-3">SKU</th>
                    <th class="p-3">Supplier</th>
                    <th class="p-3">Status</th>
                    <th class="p-3 text-right">Aksi</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($products as $product)
                    <tr class="border-b border-slate-800/60 last:border-0">
                        <td class="p-3 font-medium text-slate-200">
                            {{ $product->name }}
                            @if ($product->is_popular)
                                <span class="ml-1 rounded-full bg-accent/15 px-2 py-0.5 text-[10px] font-bold text-accent">POPULER</span>
                            @endif
                        </td>
                        <td class="p-3 text-slate-400">{{ $product->category?->name }}</td>
                        <td class="p-3 text-slate-400">{{ $product->items->count() }}</td>
                        <td class="p-3 text-slate-400">{{ $product->supplier_type ?: '—' }}</td>
                        <td class="p-3">
                            <span class="rounded-full px-2 py-0.5 text-xs font-semibold {{ $product->is_active ? 'bg-emerald-500/15 text-emerald-400' : 'bg-slate-500/15 text-slate-400' }}">
                                {{ $product->is_active ? 'Aktif' : 'Nonaktif' }}
                            </span>
                        </td>
                        <td class="p-3">
                            <div class="flex justify-end gap-2">
                                <a href="{{ route('admin.product-items.create', ['product' => $product->id]) }}" class="rounded-lg border border-slate-700 px-3 py-1.5 text-xs font-medium text-slate-300 transition hover:border-primary hover:text-white">+ SKU</a>
                                <a href="{{ route('admin.products.edit', $product) }}" class="rounded-lg border border-slate-700 px-3 py-1.5 text-xs font-medium text-slate-300 transition hover:border-primary hover:text-white">Edit</a>
                                <form action="{{ route('admin.products.destroy', $product) }}" method="POST" onsubmit="return confirm('Hapus produk ini?')">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="rounded-lg border border-red-500/40 px-3 py-1.5 text-xs font-medium text-red-400 transition hover:bg-red-500/10">Hapus</button>
                                </form>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="p-6 text-center text-slate-500">Belum ada produk.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    {{ $products->links() }}
@endsection
