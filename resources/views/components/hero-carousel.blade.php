@props(['banners'])

@if ($banners->isNotEmpty())
    <section
        x-data="carousel({{ $banners->count() }})"
        @mouseenter="stop()"
        @mouseleave="start()"
        style="aspect-ratio: 1600 / 865; max-height: 350px;"
        class="relative w-full overflow-hidden rounded-2xl select-none"
        aria-label="Banner promo"
    >
        <div class="flex h-full transition-transform duration-500 ease-out" :style="`transform: translateX(-${current * 100}%)`">
            @foreach ($banners as $banner)
                <div class="block h-full w-full shrink-0">
                    <img
                        src="{{ $banner->imageUrl() }}"
                        alt="{{ $banner->title }}"
                        class="h-full w-full rounded-2xl object-cover pointer-events-none"
                        loading="{{ $loop->first ? 'eager' : 'lazy' }}"
                    >
                    <span class="sr-only">{{ $banner->title }}</span>
                </div>
            @endforeach
        </div>

        {{-- Dot indicators --}}
        @if ($banners->count() > 1)
            <div class="absolute inset-x-0 bottom-3 flex justify-center gap-2">
                @foreach ($banners as $banner)
                    <button
                        @click="goTo({{ $loop->index }})"
                        :class="current === {{ $loop->index }} ? 'bg-white w-5' : 'bg-white/40 w-2'"
                        class="h-2 rounded-full transition-all"
                        aria-label="Banner {{ $loop->iteration }}"
                    ></button>
                @endforeach
            </div>
        @endif
    </section>
@endif
