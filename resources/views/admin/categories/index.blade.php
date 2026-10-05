@extends('layouts.admin')

@section('title', 'Kategori')
@section('page-title', 'Kategori')

@section('content')
    <div class="mb-4 flex items-center justify-between">
        <p class="text-sm text-slate-500">{{ $categories->total() }} kategori</p>
        <a href="{{ route('admin.categories.create') }}" class="btn-primary !py-2 text-sm">+ Kategori Baru</a>
    </div>

    <div class="overflow-x-auto rounded-xl border border-slate-800 bg-slate-800/40">
        <table class="w-full text-left text-sm">
            <thead>
                <tr class="border-b border-slate-800 text-xs uppercase text-slate-500">
                    <th class="p-3">Nama</th>
                    <th class="p-3">Slug</th>
                    <th class="p-3">Induk</th>
                    <th class="p-3">Produk</th>
                    <th class="p-3">Urutan</th>
                    <th class="p-3">Status</th>
                    <th class="p-3 text-right">Aksi</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($categories as $category)
                    <tr class="border-b border-slate-800/60 last:border-0">
                        <td class="p-3 font-medium text-slate-200">
                            {{ $category->icon }} {{ $category->name }}
                        </td>
                        <td class="p-3 text-slate-400">{{ $category->slug }}</td>
                        <td class="p-3 text-slate-400">{{ $category->parent?->name ?? '—' }}</td>
                        <td class="p-3 text-slate-400">{{ $category->products_count }}</td>
                        <td class="p-3 text-slate-400">{{ $category->sort_order }}</td>
                        <td class="p-3">
                            <span class="rounded-full px-2 py-0.5 text-xs font-semibold {{ $category->is_active ? 'bg-emerald-500/15 text-emerald-400' : 'bg-slate-500/15 text-slate-400' }}">
                                {{ $category->is_active ? 'Aktif' : 'Nonaktif' }}
                            </span>
                        </td>
                        <td class="p-3">
                            <div class="flex justify-end gap-2">
                                <a href="{{ route('admin.categories.edit', $category) }}" class="rounded-lg border border-slate-700 px-3 py-1.5 text-xs font-medium text-slate-300 transition hover:border-primary hover:text-white">Edit</a>
                                <form action="{{ route('admin.categories.destroy', $category) }}" method="POST" onsubmit="return confirm('Hapus kategori ini?')">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="rounded-lg border border-red-500/40 px-3 py-1.5 text-xs font-medium text-red-400 transition hover:bg-red-500/10">Hapus</button>
                                </form>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="p-6 text-center text-slate-500">Belum ada kategori.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    {{ $categories->links() }}
@endsection
