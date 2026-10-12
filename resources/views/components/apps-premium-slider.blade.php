@props(['products', 'category' => null])

@if ($products->isNotEmpty())
    <section class="space-y-3 rounded-2xl border border-primary/20 bg-gradient-to-b from-primary/10 via-slate-800/40 to-slate-800/30 p-4 shadow-sm" aria-label="Apps Premium">
        <div class="flex items-center justify-between">
            <div class="flex items-center gap-2">
                <span class="text-lg">💎</span>
                <div>
                    <h2 class="font-heading text-base font-bold text-slate-100 leading-tight">Apps Premium</h2>
                    <p class="text-xs text-slate-400">Akun resmi & berlangganan hemat</p>
                </div>
            </div>
            @if ($category)
                <a href="{{ route('category.show', $category->slug) }}" class="text-xs font-semibold text-primary-light hover:text-white flex items-center gap-1">
                    Lihat semua →
                </a>
            @endif
        </div>

        <div class="scrollbar-none -mx-4 flex gap-3 overflow-x-auto px-4 pb-1 pt-1 snap-x" style="-webkit-overflow-scrolling: touch;">
            @foreach ($products as $product)
                @php
                    $cheapestItem = $product->items->filter(fn ($item) => $item->is_active ?? true)->sortBy('sell_price')->first();
                    $url = route('product.show', [$product->category->slug, $product->slug]);
                @endphp
                <a href="{{ $url }}" class="group shrink-0 snap-start flex flex-col overflow-hidden rounded-xl border border-slate-800 bg-slate-900/80 transition hover:border-primary/60 hover:bg-slate-800/90 shadow-sm" style="width: 124px; min-width: 124px; max-width: 124px;">
                    <div class="relative w-full overflow-hidden bg-slate-950" style="height: 105px; min-height: 105px; max-height: 105px;">
                        @if ($product->iconUrl())
                            <img src="{{ $product->iconUrl() }}" alt="{{ $product->name }}" class="h-full w-full object-cover transition duration-300 group-hover:scale-105" style="width: 100%; height: 100%; object-fit: cover;" loading="lazy">
                        @else
                            <div class="grid h-full w-full place-items-center font-heading text-xl font-bold text-slate-600">
                                {{ strtoupper(substr($product->name, 0, 2)) }}
                            </div>
                        @endif

                        @if ($product->is_popular)
                            <span class="absolute left-1.5 top-1.5 rounded bg-accent px-1.5 py-0.5 text-[9px] font-bold text-slate-900 shadow">POPULER</span>
                        @endif
                    </div>

                    <div class="flex flex-1 flex-col justify-between p-2.5">
                        <p class="truncate text-xs font-semibold text-slate-100 group-hover:text-primary-light transition" title="{{ $product->name }}">{{ $product->name }}</p>
                        @if ($cheapestItem)
                            <div class="mt-1">
                                <p class="text-[10px] text-slate-500">Mulai dari</p>
                                <p class="font-heading text-xs font-bold text-secondary">Rp {{ number_format((float) $cheapestItem->sell_price, 0, ',', '.') }}</p>
                            </div>
                        @endif
                    </div>
                </a>
            @endforeach
        </div>
    </section>
@endif
