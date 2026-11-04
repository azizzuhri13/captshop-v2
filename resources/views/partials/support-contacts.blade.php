{{--
    Kontak customer service publik (dipakai footer global + halaman /kontak-cs).
    Semua nilai berasal dari App\Support\SupportContact: hanya field yang
    valid/tidak kosong yang dirender, href sudah tersanitasi, dan output
    di-escape Blade ({{ }}) sehingga aman dari HTML/atribut injection.
--}}
@php
    $cs = \App\Support\SupportContact::resolve();
@endphp

@if ($cs['whatsapp_url'] || $cs['instagram'] || $cs['email'])
    <section aria-labelledby="cs-contact-heading" class="rounded-2xl border border-slate-700 bg-slate-800/60 px-4 py-4 text-center sm:px-6">
        <h2 id="cs-contact-heading" class="font-heading text-sm font-bold text-slate-100 sm:text-base">
            Butuh Bantuan? Hubungi Customer Service
        </h2>
        <p class="mt-1 text-xs text-slate-400 sm:text-sm">
            CS Captshop aktif 24/7 — sertakan Invoice ID agar penanganan lebih cepat.
        </p>

        <div class="mt-3 flex flex-col items-stretch gap-2 sm:flex-row sm:items-center sm:justify-center sm:gap-3">
            @if ($cs['whatsapp_url'])
                <a
                    href="{{ $cs['whatsapp_url'] }}"
                    target="_blank"
                    rel="noopener noreferrer"
                    data-cs="whatsapp"
                    class="inline-flex min-h-11 items-center justify-center gap-2 rounded-xl bg-emerald-500 px-4 py-2.5 text-sm font-semibold text-slate-950 transition hover:bg-emerald-400 focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-300"
                >
                    <span aria-hidden="true">💬</span>
                    <span>WhatsApp <strong class="font-bold whitespace-nowrap">{{ $cs['whatsapp_display'] }}</strong></span>
                </a>
            @endif

            @if ($cs['instagram'])
                <a
                    href="{{ $cs['instagram'] }}"
                    target="_blank"
                    rel="noopener noreferrer"
                    data-cs="instagram"
                    class="inline-flex min-h-11 items-center justify-center gap-2 rounded-xl border border-slate-600 bg-slate-900 px-4 py-2.5 text-sm font-semibold text-slate-200 transition hover:border-slate-500 hover:text-white focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-400"
                >
                    <span aria-hidden="true">📸</span>
                    <span>Instagram{{ $cs['instagram_handle'] ? ' '.$cs['instagram_handle'] : '' }}</span>
                </a>
            @endif

            @if ($cs['email'])
                <a
                    href="mailto:{{ $cs['email'] }}"
                    data-cs="email"
                    class="inline-flex min-h-11 items-center justify-center gap-2 rounded-xl border border-slate-600 bg-slate-900 px-4 py-2.5 text-sm font-semibold text-slate-200 transition hover:border-slate-500 hover:text-white focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-400"
                >
                    <span aria-hidden="true">✉️</span>
                    <span>{{ $cs['email'] }}</span>
                </a>
            @endif
        </div>

        @if (Route::has('pages.contact'))
            <p class="mt-3 text-xs text-slate-500">
                <a href="{{ route('pages.contact') }}" class="underline decoration-slate-600 underline-offset-2 transition hover:text-slate-300">
                    Lihat halaman Kontak CS
                </a>
            </p>
        @endif
    </section>
@endif