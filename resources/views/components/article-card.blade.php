@props(['article'])

<article class="group flex flex-col overflow-hidden rounded-2xl border border-slate-800 bg-slate-800/40 transition hover:-translate-y-1 hover:border-primary/50 hover:bg-slate-800/70 shadow-lg">
    <a href="{{ route('articles.show', $article->slug) }}" class="relative aspect-video w-full overflow-hidden bg-slate-900">
        @if ($article->thumbnailUrl())
            <img src="{{ $article->thumbnailUrl() }}" alt="{{ $article->title }}" class="h-full w-full object-cover transition duration-300 group-hover:scale-105" loading="lazy">
        @else
            <div class="grid h-full w-full place-items-center bg-gradient-to-br from-slate-900 via-slate-800 to-slate-900 text-slate-600 font-heading text-3xl font-bold">
                📰
            </div>
        @endif

        @if ($article->category)
            <span class="absolute left-3 top-3 rounded-lg bg-slate-900/80 px-2.5 py-1 text-[11px] font-semibold text-primary-light backdrop-blur border border-slate-700/50">
                {{ $article->category }}
            </span>
        @endif

        <span class="absolute bottom-3 right-3 rounded-md bg-black/75 px-2 py-0.5 text-[10px] font-medium text-slate-300 backdrop-blur">
            {{ $article->reading_time_minutes ?? 1 }} mnt baca
        </span>
    </a>

    <div class="flex flex-1 flex-col p-4 sm:p-5">
        <h3 class="font-heading text-base font-bold text-slate-100 line-clamp-2 transition group-hover:text-primary-light">
            <a href="{{ route('articles.show', $article->slug) }}">
                {{ $article->title }}
            </a>
        </h3>

        <p class="mt-2 line-clamp-2 text-xs text-slate-400 leading-relaxed">
            {{ $article->excerpt ?? Str::limit(strip_tags($article->body), 110) }}
        </p>

        <div class="mt-auto pt-4 flex items-center justify-between border-t border-slate-800/80 text-[11px] text-slate-500">
            <div class="flex items-center gap-2">
                <span class="grid h-6 w-6 place-items-center rounded-full bg-primary/20 text-[10px] font-bold text-primary">
                    {{ strtoupper(substr($article->author?->name ?? 'C', 0, 1)) }}
                </span>
                <span class="text-slate-300 font-medium">{{ $article->author?->name ?? 'Admin CAPT' }}</span>
            </div>
            <time datetime="{{ $article->published_at?->toISOString() }}">
                {{ $article->published_at?->format('d M Y') }}
            </time>
        </div>
    </div>
</article>
