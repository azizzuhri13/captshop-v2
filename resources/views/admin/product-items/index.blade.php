@extends('layouts.admin')

@section('title', 'Item / SKU')
@section('page-title', 'Item / SKU')

@section('content')
    <div
        class="space-y-4"
        x-data="{
            selected: [],
            destroyUrl: '{{ route('admin.product-items.bulk-destroy') }}',
            toggleUrl: '{{ route('admin.product-items.bulk-toggle') }}',
            get count() { return this.selected.length },
            get selectableIds() {
                return [...document.querySelectorAll('[data-item-checkbox]:not(:disabled)')].map((el) => Number(el.value))
            },
            get allSelected() { return this.count > 0 && this.count === this.selectableIds.length },
            toggleAll(event) { this.selected = event.target.checked ? this.selectableIds : [] },

            // Tentukan tujuan form secara sinkron saat submit, bukan lewat
            // binding :action — browser membaca action setelah event submit,
            // jadi menulisnya di sini selalu menang.
            handleSubmit(event) {
                const intent = event.submitter?.dataset.intent

                // Gagal-aman: tanpa tombol yang jelas, jangan lakukan apa pun.
                if (! intent) {
                    event.preventDefault()
                    return
                }

                if (intent === 'destroy') {
                    event.target.action = this.destroyUrl
                    if (! confirm('Hapus ' + this.count + ' item terpilih? Tindakan ini tidak bisa dibatalkan.')) {
                        event.preventDefault()
                    }
                    return
                }

                event.target.action = this.toggleUrl
            },
        }"
    >
        <div class="flex flex-wrap items-center justify-between gap-3">
            <form method="GET" class="flex flex-wrap items-center gap-2">
                <input type="search" name="q" value="{{ request('q') }}" placeholder="Cari item/SKU..." class="input-dark !w-48 !py-2">

                <select name="product_id" onchange="this.form.submit()" class="input-dark !w-48 !py-2 text-xs">
                    <option value="">-- Semua Produk --</option>
                    @foreach ($products as $id => $name)
                        <option value="{{ $id }}" @selected(request('product_id') == $id)>{{ $name }}</option>
                    @endforeach
                </select>

                <select name="status" onchange="this.form.submit()" class="input-dark !w-32 !py-2 text-xs">
                    <option value="">-- Semua Status --</option>
                    <option value="1" @selected(request('status') === '1')>Aktif</option>
                    <option value="0" @selected(request('status') === '0')>Nonaktif</option>
                </select>

                <button type="submit" class="rounded-lg border border-slate-700 bg-slate-800 px-4 py-2 text-sm text-slate-200 hover:border-primary">Cari</button>
                @if (request()->hasAny(['q', 'product_id', 'status']))
                    <a href="{{ route('admin.product-items.index') }}" class="rounded-lg border border-slate-700 px-3 py-2 text-xs text-slate-400 hover:text-white" title="Reset filter">Reset</a>
                @endif
            </form>

            <a href="{{ route('admin.product-items.create', array_filter(['product' => request('product_id')])) }}" class="btn-primary !py-2 text-sm">+ Item Baru</a>
        </div>

        @if ($selectedProduct)
            <div class="flex items-center justify-between rounded-xl border border-primary/30 bg-primary/10 px-4 py-2.5 text-xs text-slate-300">
                <div class="flex items-center gap-2">
                    <span class="text-primary font-semibold">Filter Aktif:</span>
                    <span>Menampilkan item khusus <strong class="text-white">{{ $selectedProduct->name }}</strong> ({{ $items->total() }} item)</span>
                </div>
                <a href="{{ route('admin.product-items.index') }}" class="font-medium text-primary-light hover:underline">Lihat Semua Produk &times;</a>
            </div>
        @endif

        {{-- Bar aksi massal: hanya muncul saat ada item dicentang.
             Satu form, satu sumber ID; tiap tombol menentukan tujuannya
             sendiri lewat data-intent saat submit. --}}
        <form
            method="POST"
            action="{{ route('admin.product-items.bulk-destroy') }}"
            @submit="handleSubmit($event)"
            x-show="count > 0"
            x-cloak
            x-transition.opacity
            class="flex flex-wrap items-center gap-3 rounded-xl border border-primary/40 bg-primary/10 px-4 py-3"
        >
            @csrf
            <input type="hidden" name="is_active" x-ref="isActive" value="1">

            {{-- Satu sumber ID untuk ketiga tombol; hanya action-nya yang berganti --}}
            <template x-for="id in selected" :key="id">
                <input type="hidden" name="ids[]" :value="id">
            </template>

            <span class="text-sm text-slate-200"><strong x-text="count"></strong> item dipilih</span>

            <div class="ml-auto flex flex-wrap items-center gap-2">
                <button type="submit" data-intent="activate" @click="$refs.isActive.value = 1" class="rounded-lg border border-slate-600 bg-slate-800 px-4 py-2 text-sm font-medium text-slate-200 transition hover:border-primary">
                    Aktifkan
                </button>
                <button type="submit" data-intent="deactivate" @click="$refs.isActive.value = 0" class="rounded-lg border border-slate-600 bg-slate-800 px-4 py-2 text-sm font-medium text-slate-200 transition hover:border-primary">
                    Nonaktifkan
                </button>
                <button type="submit" data-intent="destroy" class="rounded-lg border border-red-500/50 bg-red-500/15 px-4 py-2 text-sm font-semibold text-red-300 transition hover:bg-red-500/25">
                    Hapus Terpilih
                </button>
            </div>
        </form>

        <div class="overflow-x-auto rounded-xl border border-slate-800 bg-slate-800/40">
            <table class="w-full text-left text-sm">
                <thead>
                    <tr class="border-b border-slate-800 text-xs uppercase text-slate-500">
                        <th class="w-10 p-3">
                            <input
                                type="checkbox"
                                :checked="allSelected"
                                @change="toggleAll($event)"
                                title="Pilih semua item yang bisa dihapus di halaman ini"
                                class="rounded border-slate-600 bg-slate-800 text-primary focus:ring-primary"
                            >
                        </th>
                        <th class="p-3">Item</th>
                        <th class="p-3">Grup</th>
                        <th class="p-3">Produk</th>
                        <th class="p-3">SKU Supplier</th>
                        <th class="p-3">Harga Beli</th>
                        <th class="p-3">Harga Jual</th>
                        <th class="p-3">Margin</th>
                        <th class="p-3">Flash Sale</th>
                        <th class="p-3">Status</th>
                        <th class="p-3 text-right">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($items as $item)
                        @php
                            $margin = (float) $item->sell_price - (float) $item->buy_price;
                            $flashActive = $item->isFlashSaleActive();
                            // FK transaksi memakai restrictOnDelete: item yang sudah
                            // terjual tidak boleh ikut terhapus massal.
                            $hasTransaction = (bool) $item->transactions_exists;
                        @endphp
                        <tr class="border-b border-slate-800/60 last:border-0"
                            :class="selected.includes({{ $item->id }}) ? 'bg-primary/5' : ''">
                            <td class="p-3">
                                <input
                                    type="checkbox"
                                    value="{{ $item->id }}"
                                    data-item-checkbox
                                    x-model.number="selected"
                                    @disabled($hasTransaction)
                                    @if ($hasTransaction) title="Item ini sudah punya transaksi — tidak bisa dihapus massal" @endif
                                    class="rounded border-slate-600 bg-slate-800 text-primary focus:ring-primary disabled:cursor-not-allowed disabled:opacity-40"
                                >
                            </td>
                            <td class="p-3 font-medium text-slate-200">
                                {{ $item->name }}
                                @if ($hasTransaction)
                                    <span class="ml-1 text-xs text-amber-400" title="Sudah punya transaksi">🔒</span>
                                @endif
                            </td>
                            <td class="p-3">
                                @if ($item->group)
                                    <span class="rounded-full bg-primary/15 px-2 py-0.5 text-xs font-medium text-primary-light">{{ $item->group }}</span>
                                @else
                                    <span class="text-xs text-slate-600">—</span>
                                @endif
                            </td>
                            <td class="p-3">
                                <a href="{{ route('admin.product-items.index', ['product_id' => $item->product_id]) }}" class="inline-flex items-center rounded-md border border-slate-700/60 bg-slate-800/80 px-2 py-0.5 text-xs text-slate-300 transition hover:border-primary hover:text-white" title="Filter hanya produk {{ $item->product?->name }}">
                                    {{ $item->product?->name }}
                                </a>
                            </td>
                            <td class="p-3 font-mono text-xs text-slate-400">{{ $item->supplier_item_code ?? '—' }}</td>
                            <td class="p-3 text-slate-400">Rp {{ number_format((float) $item->buy_price, 0, ',', '.') }}</td>
                            <td class="p-3 font-medium text-slate-200">Rp {{ number_format((float) $item->sell_price, 0, ',', '.') }}</td>
                            <td class="p-3 {{ $margin >= 0 ? 'text-secondary' : 'text-red-400' }}">+Rp {{ number_format($margin, 0, ',', '.') }}</td>
                            <td class="p-3">
                                @if ($flashActive)
                                    <span class="rounded-full bg-accent/15 px-2 py-0.5 text-xs font-semibold text-accent">
                                        Rp {{ number_format((float) $item->flash_sale_price, 0, ',', '.') }}
                                    </span>
                                @elseif ($item->flash_sale_price)
                                    <span class="text-xs text-slate-500">Berakhir</span>
                                @else
                                    <span class="text-xs text-slate-600">—</span>
                                @endif
                            </td>
                            <td class="p-3">
                                <span class="rounded-full px-2 py-0.5 text-xs font-semibold {{ $item->is_active ? 'bg-emerald-500/15 text-emerald-400' : 'bg-slate-500/15 text-slate-400' }}">
                                    {{ $item->is_active ? 'Aktif' : 'Nonaktif' }}
                                </span>
                            </td>
                            <td class="p-3">
                                <div class="flex justify-end gap-2">
                                    <a href="{{ route('admin.product-items.edit', $item) }}" class="rounded-lg border border-slate-700 px-3 py-1.5 text-xs font-medium text-slate-300 transition hover:border-primary hover:text-white">Edit</a>
                                    <form action="{{ route('admin.product-items.destroy', $item) }}" method="POST" onsubmit="return confirm('Hapus item ini?')">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="rounded-lg border border-red-500/40 px-3 py-1.5 text-xs font-medium text-red-400 transition hover:bg-red-500/10">Hapus</button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="11" class="p-6 text-center text-slate-500">Belum ada item.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    {{ $items->links() }}
@endsection