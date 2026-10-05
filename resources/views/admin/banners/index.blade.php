@extends('layouts.admin')

@section('title', 'Banner')
@section('page-title', 'Banner & Promo')

@section('content')
    <div class="mb-4 flex items-center justify-between">
        <p class="text-sm text-slate-500">{{ $banners->total() }} banner</p>
        <a href="{{ route('admin.banners.create') }}" class="btn-primary !py-2 text-sm">+ Banner Baru</a>
    </div>

    <div class="overflow-x-auto rounded-xl border border-slate-800 bg-slate-800/40">
        <table class="w-full text-left text-sm">
            <thead>
                <tr class="border-b border-slate-800 text-xs uppercase text-slate-500">
                    <th class="p-3">Banner</th>
                    <th class="p-3">Tipe</th>
                    <th class="p-3">Jadwal</th>
                    <th class="p-3">Urutan</th>
                    <th class="p-3">Status</th>
                    <th class="p-3 text-right">Aksi</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($banners as $banner)
                    <tr class="border-b border-slate-800/60 last:border-0">
                        <td class="p-3">
                            <div class="flex items-center gap-3">
                                @if ($banner->imageUrl())
                                    <img src="{{ $banner->imageUrl() }}" alt="{{ $banner->title }}" class="h-10 w-16 rounded-lg border border-slate-700 object-cover" onerror="this.style.visibility='hidden'">
                                @else
                                    <span class="grid h-10 w-16 place-items-center rounded-lg border border-slate-700 text-xs text-slate-600">?</span>
                                @endif
                                <div>
                                    <p class="font-medium text-slate-200">{{ $banner->title }}</p>
                                    <p class="max-w-xs truncate text-xs text-slate-500">{{ $banner->url ?? '—' }}</p>
                                </div>
                            </div>
                        </td>
                        <td class="p-3 text-slate-400">{{ ucfirst($banner->type) }}</td>
                        <td class="p-3 text-xs text-slate-400">
                            {{ $banner->start_at?->format('d M') ?? '∞' }} → {{ $banner->end_at?->format('d M') ?? '∞' }}
                        </td>
                        <td class="p-3 text-slate-400">{{ $banner->sort_order }}</td>
                        <td class="p-3">
                            <span class="rounded-full px-2 py-0.5 text-xs font-semibold {{ $banner->is_active ? 'bg-emerald-500/15 text-emerald-400' : 'bg-slate-500/15 text-slate-400' }}">
                                {{ $banner->is_active ? 'Aktif' : 'Nonaktif' }}
                            </span>
                        </td>
                        <td class="p-3">
                            <div class="flex justify-end gap-2">
                                <a href="{{ route('admin.banners.edit', $banner) }}" class="rounded-lg border border-slate-700 px-3 py-1.5 text-xs font-medium text-slate-300 transition hover:border-primary hover:text-white">Edit</a>
                                <form action="{{ route('admin.banners.destroy', $banner) }}" method="POST" onsubmit="return confirm('Hapus banner ini?')">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="rounded-lg border border-red-500/40 px-3 py-1.5 text-xs font-medium text-red-400 transition hover:bg-red-500/10">Hapus</button>
                                </form>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="p-6 text-center text-slate-500">Belum ada banner.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    {{ $banners->links() }}
@endsection
