@props(['categories'])

<section aria-label="Kategori produk">
    <div class="scrollbar-none -mx-4 flex gap-2 overflow-x-auto px-4 py-1 sm:mx-0 sm:flex-wrap sm:overflow-visible sm:px-0">
        @foreach ($categories as $category)
            <a
                href="{{ route('category.show', $category->slug) }}"
                class="shrink-0 rounded-full border border-slate-700 bg-slate-800/70 px-4 py-2 text-sm font-medium text-slate-300 transition hover:border-primary hover:bg-primary/10 hover:text-white focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary"
            >{{ $category->name }}</a>
        @endforeach
    </div>
</section>
