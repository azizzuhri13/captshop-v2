@extends('layouts.admin')

@section('title', 'Artikel & Blog')
@section('page-title', 'Artikel & Panduan Game')

@section('content')
    <div class="mb-4 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div class="flex items-center gap-3">
            <p class="text-sm text-slate-500">{{ $articles->total() }} artikel</p>
            @if (request('category') || request('q'))
                <a href="{{ route('admin.articles.index') }}" class="text-xs text-primary-light hover:underline">Reset filter</a>
            @endif
        </div>
        <div class="flex items-center gap-2">
            <form action="{{ route('admin.articles.index') }}" method="GET" class="flex gap-2">
                <input type="text" name="q" value="{{ request('q') }}" placeholder="Cari artikel..." class="input-dark !py-1.5 text-xs w-48">
            </form>
            <a href="{{ route('admin.articles.create') }}" class="btn-primary !py-2 text-sm shrink-0">+ Artikel Baru</a>
        </div>
    </div>

    <div class="overflow-x-auto rounded-xl border border-slate-800 bg-slate-800/40">
        <table class="w-full text-left text-sm">
            <thead>
                <tr class="border-b border-slate-800 text-xs uppercase text-slate-500">
                    <th class="p-3">Artikel</th>
                    <th class="p-3">Kategori</th>
                    <th class="p-3">Game Terkait</th>
                    <th class="p-3">Views</th>
                    <th class="p-3">Status</th>
                    <th class="p-3">Tanggal</th>
                    <th class="p-3 text-right">Aksi</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($articles as $article)
                    <tr class="border-b border-slate-800/60 last:border-0 hover:bg-slate-800/30">
                        <td class="p-3">
                            <div class="flex items-center gap-3">
                                @if ($article->thumbnailUrl())
                                    <img src="{{ $article->thumbnailUrl() }}" alt="{{ $article->title }}" class="h-10 w-16 rounded-lg border border-slate-700 object-cover" onerror="this.style.visibility='hidden'">
                                @else
                                    <span class="grid h-10 w-16 place-items-center rounded-lg border border-slate-700 text-xs text-slate-600 bg-slate-800">📝</span>
                                @endif
                                <div class="min-w-0 max-w-xs">
                                    <a href="{{ route('articles.show', $article->slug) }}" target="_blank" class="font-medium text-slate-200 hover:text-primary-light line-clamp-1">
                                        {{ $article->title }}
                                    </a>
                                    <p class="truncate text-xs text-slate-500">/artikel/{{ $article->slug }}</p>
                                </div>
                            </div>
                        </td>
                        <td class="p-3 text-slate-400 text-xs">
                            <span class="rounded bg-slate-700/60 px-2 py-0.5 text-slate-300">{{ $article->category ?? '—' }}</span>
                        </td>
                        <td class="p-3 text-slate-400 text-xs">
                            {{ $article->relatedProduct?->name ?? '—' }}
                        </td>
                        <td class="p-3 text-slate-400 text-xs">
                            👁 {{ number_format($article->views_count) }}
                        </td>
                        <td class="p-3">
                            @if ($article->is_published)
                                <span class="rounded-full bg-emerald-500/10 px-2 py-0.5 text-xs font-medium text-emerald-400">Published</span>
                            @else
                                <span class="rounded-full bg-amber-500/10 px-2 py-0.5 text-xs font-medium text-amber-400">Draft</span>
                            @endif
                        </td>
                        <td class="p-3 text-xs text-slate-500 whitespace-nowrap">
                            {{ $article->published_at?->format('d M Y') ?? '—' }}
                        </td>
                        <td class="p-3 text-right whitespace-nowrap">
                            <a href="{{ route('admin.articles.edit', $article) }}" class="rounded-lg border border-slate-700 px-3 py-1.5 text-xs font-medium text-slate-300 transition hover:border-primary hover:text-white">Edit</a>
                            <form action="{{ route('admin.articles.destroy', $article) }}" method="POST" class="inline" onsubmit="return confirm('Hapus artikel ini?')">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="rounded-lg border border-red-500/30 px-3 py-1.5 text-xs font-medium text-red-400 transition hover:bg-red-500/10">Hapus</button>
                            </form>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7" class="p-8 text-center text-slate-500">
                            Belum ada artikel. Klik <strong>+ Artikel Baru</strong> untuk membuat postingan pertama.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    @if ($articles->hasPages())
        <div class="mt-4">
            {{ $articles->links() }}
        </div>
    @endif
@endsection
