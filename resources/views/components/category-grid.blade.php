@props(['categories'])

<section aria-label="Kategori produk">
    <div class="scrollbar-none -mx-4 flex gap-3 overflow-x-auto px-4 py-1 sm:mx-0 sm:grid sm:grid-cols-3 sm:overflow-visible sm:px-0 lg:grid-cols-6">
        @foreach ($categories as $category)
            <a
                href="{{ route('category.show', $category->slug) }}"
                class="group flex w-20 shrink-0 flex-col items-center gap-2 sm:w-auto"
            >
                <span class="grid h-16 w-16 place-items-center overflow-hidden rounded-2xl border border-slate-800 bg-slate-800/60 text-2xl transition group-hover:border-primary group-hover:bg-primary/10">
                    @if ($category->iconUrl())
                        <img src="{{ $category->iconUrl() }}" alt="{{ $category->name }}" class="h-full w-full object-cover" loading="lazy">
                    @else
                        {{ $category->icon ?: '🎮' }}
                    @endif
                </span>
                <span class="text-center text-xs font-medium text-slate-300 group-hover:text-white">{{ $category->name }}</span>
            </a>
        @endforeach
    </div>
</section>
