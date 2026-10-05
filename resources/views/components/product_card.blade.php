@props(['product'])

@php
    $cheapestItem = $product->items->filter(fn ($item) => $item->is_active ?? true)->sortBy('sell_price')->first();
    $url = route('product.show', [$product->category->slug, $product->slug]);
@endphp

<a href="{{ $url }}" class="group flex flex-col overflow-hidden rounded-xl border border-slate-800 bg-slate-800/40 transition hover:border-primary/60 hover:bg-slate-800/70">
    <div class="relative aspect-square w-full overflow-hidden bg-slate-800">
        @if ($product->iconUrl())
            <img src="{{ $product->iconUrl() }}" alt="{{ $product->name }}" class="h-full w-full object-cover transition group-hover:scale-105" loading="lazy">
        @else
            <div class="grid h-full w-full place-items-center font-heading text-3xl font-bold text-slate-600">
                {{ strtoupper(substr($product->name, 0, 2)) }}
            </div>
        @endif

        @if ($product->is_popular)
            <span class="absolute left-2 top-2 rounded-full bg-accent px-2 py-0.5 text-[10px] font-bold text-slate-900">POPULER</span>
        @endif
    </div>

    <div class="flex flex-1 flex-col gap-1 p-3">
        <p class="line-clamp-2 text-sm font-semibold leading-snug text-slate-100">{{ $product->name }}</p>
        @if ($cheapestItem)
            <p class="mt-auto text-xs text-slate-500">Mulai dari</p>
            <p class="font-heading text-sm font-bold text-secondary">Rp {{ number_format((float) $cheapestItem->sell_price, 0, ',', '.') }}</p>
        @endif
    </div>
</a>
