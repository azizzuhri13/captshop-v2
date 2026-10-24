@extends('layouts.admin')

@section('title', 'Detail '.$transaction->invoice_id)
@section('page-title', 'Detail Transaksi')

@section('content')
    <div class="grid gap-6 lg:grid-cols-3">
        {{-- Info utama --}}
        <div class="space-y-4 lg:col-span-2">
            <div class="rounded-xl border border-slate-800 bg-slate-800/40 p-5">
                <div class="mb-4 flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                    <h2 class="break-all font-heading text-base font-bold text-slate-100 sm:text-lg">{{ $transaction->invoice_id }}</h2>
                    <div>
                        <span class="inline-block rounded-full px-3 py-1 text-xs font-semibold {{ $transaction->statusBadgeClass() }}">{{ strtoupper($transaction->status) }}</span>
                    </div>
                </div>

                @if ($transaction->needs_manual_review)
                    <div class="mb-4 rounded-lg border border-amber-500/40 bg-amber-500/10 p-3 text-sm">
                        <p class="font-semibold text-amber-200">⚠️ Ditahan untuk tindakan admin — retry otomatis dihentikan</p>
                        <p class="mt-1 text-amber-200/90">{{ $transaction->manual_review_reason ?? 'Order ini butuh tindakan manual.' }}</p>
                        <p class="mt-1 text-xs text-amber-200/70">
                            Supplier: {{ $transaction->supplier_name ?? '—' }}
                            · Percobaan otomatis: {{ (int) $transaction->supplier_attempts }}
                            @if ($transaction->supplier_last_attempt_at)
                                · Percobaan terakhir: {{ $transaction->supplier_last_attempt_at->format('d/m/Y H:i') }}
                            @endif
                        </p>
                    </div>
                @endif

                <dl class="grid gap-x-6 gap-y-3 text-sm sm:grid-cols-2">
                    <div class="flex justify-between gap-4">
                        <dt class="text-slate-500">Produk</dt>
                        <dd class="text-right font-medium text-slate-200">{{ $transaction->productItem?->product?->name }}</dd>
                    </div>
                    <div class="flex justify-between gap-4">
                        <dt class="text-slate-500">Item</dt>
                        <dd class="text-right font-medium text-slate-200">{{ $transaction->productItem?->name }}</dd>
                    </div>
                    @foreach ($transaction->customer_data ?? [] as $label => $value)
                        <div class="flex justify-between gap-4">
                            <dt class="text-slate-500">{{ ucfirst(str_replace('_', ' ', $label)) }}</dt>
                            <dd class="text-right font-medium text-slate-200">{{ $value }}</dd>
                        </div>
                    @endforeach
                    <div class="flex justify-between gap-4">
                        <dt class="text-slate-500">No. WhatsApp</dt>
                        <dd class="text-right font-medium text-slate-200">{{ $transaction->customer_phone }}</dd>
                    </div>
                    <div class="flex justify-between gap-4">
                        <dt class="text-slate-500">Email</dt>
                        <dd class="text-right font-medium text-slate-200">{{ $transaction->customer_email ?? '—' }}</dd>
                    </div>
                    <div class="flex justify-between gap-4">
                        <dt class="text-slate-500">Metode Bayar</dt>
                        <dd class="text-right font-medium text-slate-200">{{ $transaction->payment_method }} — {{ $transaction->payment_channel }}</dd>
                    </div>
                    <div class="flex justify-between gap-4">
                        <dt class="text-slate-500">Ref Gateway</dt>
                        <dd class="text-right font-mono text-xs font-medium text-slate-200">{{ $transaction->payment_ref ?? '—' }}</dd>
                    </div>
                </dl>

                <div class="mt-4 grid grid-cols-4 gap-2 border-t border-slate-800 pt-4 text-center text-xs">
                    <div>
                        <p class="text-slate-500">Harga</p>
                        <p class="mt-0.5 font-semibold text-slate-200">Rp {{ number_format((float) $transaction->amount, 0, ',', '.') }}</p>
                    </div>
                    <div>
                        <p class="text-slate-500">Fee</p>
                        <p class="mt-0.5 font-semibold text-slate-200">Rp {{ number_format((float) $transaction->fee, 0, ',', '.') }}</p>
                    </div>
                    <div>
                        <p class="text-slate-500">Total</p>
                        <p class="mt-0.5 font-semibold text-secondary">Rp {{ number_format((float) $transaction->total, 0, ',', '.') }}</p>
                    </div>
                    <div>
                        <p class="text-slate-500">Profit</p>
                        <p class="mt-0.5 font-semibold text-emerald-400">Rp {{ number_format((float) $transaction->profit, 0, ',', '.') }}</p>
                    </div>
                </div>
            </div>

            {{-- Timeline --}}
            <div class="rounded-xl border border-slate-800 bg-slate-800/40 p-5">
                <h3 class="mb-3 font-heading text-sm font-bold text-slate-200">Timeline</h3>

                <ol class="space-y-3 text-sm">
                    @foreach ([
                        ['Dibuat', $transaction->created_at, 'emerald'],
                        ['Dibayar', $transaction->paid_at, 'emerald'],
                        ['Diproses', $transaction->processed_at, 'sky'],
                        ['Selesai', $transaction->completed_at, 'emerald'],
                        ['Hangus', $transaction->expired_at, 'slate'],
                    ] as [$label, $time, $color])
                        <li class="flex items-center gap-3">
                            <span class="h-2 w-2 shrink-0 rounded-full {{ $time ? ($color === 'sky' ? 'bg-sky-400' : ($color === 'slate' ? 'bg-red-500' : 'bg-emerald-400')) : 'bg-slate-700' }}"></span>
                            <span class="text-slate-400">{{ $label }}</span>
                            <span class="ml-auto text-xs {{ $time ? 'text-slate-300' : 'text-slate-600' }}">{{ $time?->format('d M Y, H:i') ?? '—' }}</span>
                        </li>
                    @endforeach
                </ol>
            </div>

            {{-- Supplier response --}}
            @if ($transaction->supplier_response)
                @php
                    $decoded = json_decode($transaction->supplier_response, true);
                    $prettyJson = is_array($decoded) ? json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) : $transaction->supplier_response;
                @endphp
                <div class="rounded-xl border border-slate-800 bg-slate-800/40 p-5">
                    <h3 class="mb-3 font-heading text-sm font-bold text-slate-200">Response Supplier</h3>
                    <dl class="space-y-2 text-sm">
                        <div class="flex justify-between gap-4">
                            <dt class="text-slate-500">Supplier Ref</dt>
                            <dd class="font-mono text-xs text-slate-200">{{ $transaction->supplier_ref ?? '—' }}</dd>
                        </div>
                        <div class="flex justify-between gap-4">
                            <dt class="text-slate-500">SN</dt>
                            <dd class="break-all font-mono text-xs text-slate-200">{{ $transaction->supplier_sn ?? '—' }}</dd>
                        </div>
                    </dl>
                    <details class="mt-2">
                        <summary class="cursor-pointer text-xs font-medium text-primary-light">Raw response</summary>
                        <pre class="mt-2 max-h-56 overflow-auto rounded-lg bg-slate-950 p-3 text-[11px] leading-relaxed text-slate-300">{{ $prettyJson }}</pre>
                    </details>
                </div>
            @endif
        </div>

        {{-- Panel aksi --}}
        <div class="space-y-3">
            <div class="rounded-xl border border-slate-800 bg-slate-800/40 p-5">
                <h3 class="mb-3 font-heading text-sm font-bold text-slate-200">Manual Action</h3>

                <div class="space-y-2">
                    <form action="{{ route('admin.transactions.retry-fulfillment', $transaction) }}" method="POST" onsubmit="return confirm('Jalankan ulang fulfillment ke supplier?')">
                        @csrf
                        <button type="submit" class="w-full rounded-lg border border-sky-500/50 px-4 py-2.5 text-sm font-medium text-sky-400 transition hover:bg-sky-500/10">
                            🔄 Retry Fulfillment
                        </button>
                    </form>

                    <form action="{{ route('admin.transactions.mark-completed', $transaction) }}" method="POST" onsubmit="return confirm('Tandai transaksi ini selesai secara manual?')">
                        @csrf
                        <button type="submit" class="w-full rounded-lg border border-secondary/50 px-4 py-2.5 text-sm font-medium text-secondary transition hover:bg-secondary/10">
                            ✅ Mark as Completed
                        </button>
                    </form>

                    <a href="{{ route('invoice.show', $transaction->invoice_id) }}" target="_blank" class="block w-full rounded-lg border border-slate-700 px-4 py-2.5 text-center text-sm font-medium text-slate-300 transition hover:border-primary hover:text-white">
                        Lihat Invoice Publik ↗
                    </a>
                </div>
            </div>

            <div class="rounded-xl border border-slate-800 bg-slate-800/40 p-5 text-xs text-slate-500">
                <p>Gunakan <b>Retry Fulfillment</b> bila pembayaran sudah masuk namun top-up gagal/stuck di supplier. <b>Mark as Completed</b> hanya untuk penyelesaian manual setelah verifikasi CS.</p>
                <p class="mt-2">Transaksi yang <b>ditahan untuk tindakan admin</b> berhenti dikirim otomatis ke supplier. Retry Fulfillment akan membuka penahan itu dan mengirim sekali lagi ke supplier.</p>
            </div>
        </div>
    </div>
@endsection
