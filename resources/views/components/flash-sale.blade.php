@props(['items'])

@if ($items->isNotEmpty())
    @php
        $deadline = $items->min('flash_sale_end');
    @endphp

    <section
        x-data="{
            deadline: new Date('{{ $deadline->toIso8601String() }}').getTime(),
            remaining: 0,
            tick() {
                this.remaining = Math.max(0, Math.floor((this.deadline - Date.now()) / 1000));
            },
            init() {
                this.tick();
                setInterval(() => this.tick(), 1000);
            },
            get parts() {
                const h = String(Math.floor(this.remaining / 3600)).padStart(2, '0');
                const m = String(Math.floor((this.remaining % 3600) / 60)).padStart(2, '0');
                const s = String(this.remaining % 60).padStart(2, '0');
                return { h, m, s };
            },
        }"
        class="rounded-2xl border border-accent/30 bg-gradient-to-br from-accent/10 via-slate-800/40 to-slate-800/40 p-4"
        aria-label="Flash sale"
    >
        <div class="mb-4 flex items-center justify-between">
            <div class="flex items-center gap-2">
                <span class="text-lg">🔥</span>
                <h2 class="font-heading text-base font-bold text-slate-100">Flash Sale</h2>
            </div>

            <div class="flex items-center gap-1 font-mono text-sm" aria-label="Countdown">
                <span class="rounded-md bg-slate-900 px-2 py-1 font-bold text-accent" x-text="parts.h">00</span>:
                <span class="rounded-md bg-slate-900 px-2 py-1 font-bold text-accent" x-text="parts.m">00</span>:
                <span class="rounded-md bg-slate-900 px-2 py-1 font-bold text-accent" x-text="parts.s">00</span>
            </div>
        </div>

        <div class="scrollbar-none -mx-4 flex gap-3 overflow-x-auto px-4">
            @foreach ($items as $item)
                @php
                    $discount = $item->sell_price > 0
                        ? round((1 - $item->flash_sale_price / $item->sell_price) * 100)
                        : 0;
                    $url = route('product.show', [$item->product->category->slug, $item->product->slug]);
                @endphp

                <a href="{{ $url }}" class="w-36 shrink-0 rounded-xl border border-slate-800 bg-slate-900/60 p-3 transition hover:border-accent/60">
                    <div class="mb-2 grid h-16 w-16 place-items-center overflow-hidden rounded-lg bg-slate-800">
                        @if ($item->product->iconUrl())
                            <img src="{{ $item->product->iconUrl() }}" alt="{{ $item->product->name }}" class="h-full w-full object-cover" loading="lazy">
                        @else
                            <span class="font-heading text-lg font-bold text-slate-600">{{ strtoupper(substr($item->product->name, 0, 2)) }}</span>
                        @endif
                    </div>

                    <p class="line-clamp-1 text-xs font-semibold text-slate-200">{{ $item->product->name }}</p>
                    <p class="line-clamp-1 text-[11px] text-slate-500">{{ $item->name }}</p>

                    <p class="mt-1 font-heading text-sm font-bold text-accent">Rp {{ number_format((float) $item->flash_sale_price, 0, ',', '.') }}</p>
                    <p class="text-[11px] text-slate-500 line-through">Rp {{ number_format((float) $item->sell_price, 0, ',', '.') }}</p>

                    @if ($discount > 0)
                        <span class="mt-1 inline-block rounded-full bg-accent px-2 py-0.5 text-[10px] font-bold text-slate-900">-{{ $discount }}%</span>
                    @endif
                </a>
            @endforeach
        </div>
    </section>
@endif
