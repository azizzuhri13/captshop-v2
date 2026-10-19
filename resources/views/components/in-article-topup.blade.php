@props(['product'])

@php
    $cheapestItem = $product->items->filter(fn ($item) => $item->is_active ?? true)->sortBy('sell_price')->first();
    $targetUrl = route('product.show', [$product->category->slug, $product->slug]);
@endphp

<div class="my-8 overflow-hidden rounded-2xl border border-primary/40 bg-gradient-to-r from-slate-900 via-slate-800 to-slate-900 p-4 sm:p-5 shadow-lg shadow-primary/10 not-prose">
    <div class="flex flex-col sm:flex-row items-center justify-between gap-4">
        <div class="flex items-center gap-3.5 w-full sm:w-auto">
            @if ($product->iconUrl())
                <img src="{{ $product->iconUrl() }}" alt="{{ $product->name }}" class="h-14 w-14 shrink-0 rounded-xl object-cover border border-slate-700 shadow">
            @else
                <div class="grid h-14 w-14 shrink-0 place-items-center rounded-xl bg-slate-800 font-heading text-lg font-bold text-slate-500 border border-slate-700">
                    {{ strtoupper(substr($product->name, 0, 2)) }}
                </div>
            @endif
            <div>
                <div class="flex items-center gap-2">
                    <span class="rounded bg-primary/20 px-2 py-0.5 text-[10px] font-bold text-primary tracking-wide">REKOMENDASI CAPT</span>
                    <span class="text-xs text-slate-400">Instan 24 Jam</span>
                </div>
                <h4 class="font-heading text-base font-bold text-white mt-0.5">{{ $product->name }}</h4>
                @if ($cheapestItem)
                    <p class="text-xs text-slate-300">
                        Harga mulai <span class="font-bold text-secondary">Rp {{ number_format((float) $cheapestItem->sell_price, 0, ',', '.') }}</span>
                    </p>
                @endif
            </div>
        </div>

        <a href="{{ $targetUrl }}" class="btn-primary w-full sm:w-auto !py-2.5 !px-5 text-sm font-bold shadow-md shrink-0 text-center">
            Top Up Sekarang ➔
        </a>
    </div>
</div>
