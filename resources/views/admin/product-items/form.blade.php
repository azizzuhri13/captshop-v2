@php
    $productItem = $productItem ?? null;
    $products = $products ?? collect();
    $preselectedProduct = $preselectedProduct ?? null;
    $existingGroups = $existingGroups ?? [];
@endphp

<form
    action="{{ $productItem ? route('admin.product-items.update', $productItem) : route('admin.product-items.store') }}"
    method="POST"
    class="space-y-4"
>
    @csrf
    @if ($productItem)
        @method('PUT')
    @endif

    <div class="grid gap-4 sm:grid-cols-2">
        <div class="sm:col-span-2">
            <label class="mb-1.5 block text-sm font-medium text-slate-300">Produk *</label>
            <select name="product_id" required class="input-dark">
                <option value="">— Pilih produk —</option>
                @foreach ($products as $id => $name)
                    <option value="{{ $id }}" @selected(old('product_id', $productItem?->product_id ?? $preselectedProduct) == $id)>{{ $name }}</option>
                @endforeach
            </select>
        </div>

        <div>
            <label class="mb-1.5 block text-sm font-medium text-slate-300">Nama Item *</label>
            <input type="text" name="name" value="{{ old('name', $productItem?->name) }}" required class="input-dark" placeholder="86 Diamonds">
        </div>

        <div>
            <label class="mb-1.5 block text-sm font-medium text-slate-300">Grup / Sub-bagian</label>
            <input
                type="text"
                name="group"
                value="{{ old('group', $productItem?->group) }}"
                class="input-dark"
                placeholder="Special Items"
                list="item-group-options"
            >
            <p class="mt-1 text-xs text-slate-500">Kosongkan bila tidak perlu dikelompokkan. Item dengan grup sama akan tampil di satu sub-bagian.</p>
            <datalist id="item-group-options">
                @foreach ($existingGroups ?? [] as $groupName)
                    <option value="{{ $groupName }}"></option>
                @endforeach
            </datalist>
        </div>

        <div>
            <label class="mb-1.5 block text-sm font-medium text-slate-300">SKU Supplier</label>
            <input type="text" name="supplier_item_code" value="{{ old('supplier_item_code', $productItem?->supplier_item_code) }}" class="input-dark" placeholder="ml-86">
        </div>

        <div>
            <label class="mb-1.5 block text-sm font-medium text-slate-300">Harga Beli (dari supplier) *</label>
            <input type="number" name="buy_price" step="0.01" min="0" value="{{ old('buy_price', $productItem?->buy_price) }}" required class="input-dark" placeholder="13000">
        </div>

        <div>
            <label class="mb-1.5 block text-sm font-medium text-slate-300">Harga Jual *</label>
            <input type="number" name="sell_price" step="0.01" min="0" value="{{ old('sell_price', $productItem?->sell_price) }}" required class="input-dark" placeholder="15000">
        </div>

        <div>
            <label class="mb-1.5 block text-sm font-medium text-slate-300">Urutan</label>
            <input type="number" name="sort_order" min="0" max="9999" value="{{ old('sort_order', $productItem?->sort_order ?? 0) }}" class="input-dark">
        </div>
    </div>

    {{-- Flash Sale --}}
    <section class="rounded-xl border border-accent/30 bg-accent/5 p-4">
        <h3 class="mb-3 font-heading text-sm font-bold text-slate-100">⚡ Flash Sale (opsional)</h3>

        <div class="grid gap-4 sm:grid-cols-3">
            <div>
                <label class="mb-1.5 block text-sm font-medium text-slate-300">Harga Promo</label>
                <input type="number" name="flash_sale_price" step="0.01" min="0" value="{{ old('flash_sale_price', $productItem?->flash_sale_price) }}" class="input-dark" placeholder="12000">
            </div>
            <div>
                <label class="mb-1.5 block text-sm font-medium text-slate-300">Mulai</label>
                <input type="datetime-local" name="flash_sale_start" value="{{ old('flash_sale_start', $productItem?->flash_sale_start?->format('Y-m-d\TH:i')) }}" class="input-dark">
            </div>
            <div>
                <label class="mb-1.5 block text-sm font-medium text-slate-300">Selesai</label>
                <input type="datetime-local" name="flash_sale_end" value="{{ old('flash_sale_end', $productItem?->flash_sale_end?->format('Y-m-d\TH:i')) }}" class="input-dark">
            </div>
        </div>
    </section>

    <label class="flex items-center gap-2 text-sm text-slate-300">
        <input type="checkbox" name="is_active" value="1" @checked(old('is_active', $productItem?->is_active ?? true)) class="rounded border-slate-600 bg-slate-800 text-primary focus:ring-primary">
        Aktif
    </label>

    @error('name')<p class="text-xs text-red-400">{{ $message }}</p>@enderror
    @error('product_id')<p class="text-xs text-red-400">{{ $message }}</p>@enderror
    @error('sell_price')<p class="text-xs text-red-400">{{ $message }}</p>@enderror

    <div class="flex gap-2 pt-2">
        <button type="submit" class="btn-primary">{{ $productItem ? 'Simpan Perubahan' : 'Buat Item' }}</button>
        <a href="{{ route('admin.product-items.index') }}" class="rounded-lg border border-slate-700 px-4 py-3 text-sm font-medium text-slate-300 transition hover:border-slate-500">Batal</a>
    </div>
</form>
