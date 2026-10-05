@php
    $product = $product ?? null;
    $categories = $categories ?? collect();
@endphp

<form
    action="{{ $product ? route('admin.products.update', $product) : route('admin.products.store') }}"
    method="POST"
    enctype="multipart/form-data"
    class="space-y-5"
    x-data="{
        fields: {{ json_encode(old('input_fields', $product?->input_fields ?? [])) }},
        addField() {
            this.fields.push({ name: '', label: '', type: 'text', required: false, placeholder: '' });
        },
        removeField(index) {
            this.fields.splice(index, 1);
        },
    }"
>
    @csrf
    @if ($product)
        @method('PUT')
    @endif

    <div class="grid gap-4 sm:grid-cols-2">
        <div>
            <label class="mb-1.5 block text-sm font-medium text-slate-300">Nama Produk *</label>
            <input type="text" name="name" value="{{ old('name', $product?->name) }}" required class="input-dark" placeholder="Mobile Legends: Bang Bang">
        </div>

        <div>
            <label class="mb-1.5 block text-sm font-medium text-slate-300">Slug *</label>
            <input type="text" name="slug" value="{{ old('slug', $product?->slug) }}" required class="input-dark" placeholder="mobile-legends">
        </div>

        <div>
            <label class="mb-1.5 block text-sm font-medium text-slate-300">Kategori *</label>
            <select name="category_id" required class="input-dark">
                <option value="">— Pilih kategori —</option>
                @foreach ($categories as $id => $name)
                    <option value="{{ $id }}" @selected(old('category_id', $product?->category_id) == $id)>{{ $name }}</option>
                @endforeach
            </select>
        </div>

        <div>
            <label class="mb-1.5 block text-sm font-medium text-slate-300">Kode Produk</label>
            <input type="text" name="code" value="{{ old('code', $product?->code) }}" class="input-dark" placeholder="MLBB">
        </div>

        <div>
            <label class="mb-1.5 block text-sm font-medium text-slate-300">Ikon (upload gambar)</label>
            @if ($product?->iconUrl())
                <img src="{{ $product->iconUrl() }}" alt="Ikon saat ini" class="mb-2 h-16 w-16 rounded-xl border border-slate-700 object-cover">
            @endif
            <input
                type="file"
                name="icon"
                accept="image/jpeg,image/png,image/webp"
                class="block w-full cursor-pointer rounded-lg border border-slate-700 bg-slate-800 text-sm text-slate-300 file:mr-3 file:cursor-pointer file:rounded-l-lg file:border-0 file:bg-primary file:px-4 file:py-2.5 file:text-sm file:font-semibold file:text-white hover:border-primary"
            >
            <p class="mt-1 text-[11px] text-slate-500">JPEG/PNG/WebP, maks 2MB (kosongkan untuk tetap memakai ikon saat ini).</p>
        </div>

        <div>
            <label class="mb-1.5 block text-sm font-medium text-slate-300">Banner (upload gambar)</label>
            @if ($product?->bannerUrl())
                <img src="{{ $product->bannerUrl() }}" alt="Banner saat ini" class="mb-2 h-16 w-full max-w-xs rounded-xl border border-slate-700 object-cover">
            @endif
            <input
                type="file"
                name="banner"
                accept="image/jpeg,image/png,image/webp"
                class="block w-full cursor-pointer rounded-lg border border-slate-700 bg-slate-800 text-sm text-slate-300 file:mr-3 file:cursor-pointer file:rounded-l-lg file:border-0 file:bg-primary file:px-4 file:py-2.5 file:text-sm file:font-semibold file:text-white hover:border-primary"
            >
            <p class="mt-1 text-[11px] text-slate-500">JPEG/PNG/WebP, maks 2MB (kosongkan untuk tetap memakai banner saat ini).</p>
        </div>

        <div>
            <label class="mb-1.5 block text-sm font-medium text-slate-300">Tipe Supplier</label>
            <select name="supplier_type" class="input-dark">
                <option value="">— Tanpa supplier —</option>
                @foreach (['digiflazz', 'apigames', 'vocagame', 'manual'] as $type)
                    <option value="{{ $type }}" @selected(old('supplier_type', $product?->supplier_type) === $type)>{{ ucfirst($type) }}</option>
                @endforeach
            </select>
        </div>

        <div>
            <label class="mb-1.5 block text-sm font-medium text-slate-300">Kode SKU Supplier</label>
            <input type="text" name="supplier_code" value="{{ old('supplier_code', $product?->supplier_code) }}" class="input-dark" placeholder="mobile-legend">
        </div>

        <div>
            <label class="mb-1.5 block text-sm font-medium text-slate-300">Urutan</label>
            <input type="number" name="sort_order" min="0" max="9999" value="{{ old('sort_order', $product?->sort_order ?? 0) }}" class="input-dark">
        </div>
    </div>

    <div class="grid gap-4 sm:grid-cols-2">
        <div>
            <label class="mb-1.5 block text-sm font-medium text-slate-300">Deskripsi</label>
            <textarea name="description" rows="3" class="input-dark">{{ old('description', $product?->description) }}</textarea>
        </div>
        <div>
            <label class="mb-1.5 block text-sm font-medium text-slate-300">Instruksi Pemesanan</label>
            <textarea name="instruction" rows="3" class="input-dark">{{ old('instruction', $product?->instruction) }}</textarea>
        </div>
    </div>

    {{-- Input Fields Builder (dinamis per game) --}}
    <section class="rounded-xl border border-primary/30 bg-primary/5 p-4">
        <div class="mb-3 flex items-center justify-between">
            <div>
                <h3 class="font-heading text-sm font-bold text-slate-100">Input Fields (Form Dinamis)</h3>
                <p class="text-xs text-slate-500">Konfigurasi field yang diisi pembeli, mis. User ID & Zone ID.</p>
            </div>
            <button type="button" @click="addField" class="rounded-lg border border-primary/50 px-3 py-1.5 text-xs font-semibold text-primary-light transition hover:bg-primary/10">+ Field</button>
        </div>

        <div class="space-y-2">
            <template x-for="(field, index) in fields" :key="index">
                <div class="grid grid-cols-2 gap-2 rounded-lg border border-slate-700 bg-slate-800 p-3 sm:grid-cols-12">
                    <input type="text" x-model="field.name" placeholder="name (user_id)" class="input-dark !py-2 !text-xs sm:col-span-3">
                    <input type="text" x-model="field.label" placeholder="label (User ID)" class="input-dark !py-2 !text-xs sm:col-span-3">
                    <select x-model="field.type" class="input-dark !py-2 !text-xs sm:col-span-2">
                        <option value="text">text</option>
                        <option value="tel">tel</option>
                        <option value="number">number</option>
                        <option value="email">email</option>
                    </select>
                    <input type="text" x-model="field.placeholder" placeholder="placeholder" class="input-dark !py-2 !text-xs sm:col-span-3">
                    <div class="flex items-center justify-between gap-2 sm:col-span-1">
                        <label class="flex items-center gap-1 text-xs text-slate-400" title="Wajib diisi">
                            <input type="checkbox" x-model="field.required" class="rounded border-slate-600 bg-slate-900 text-primary">
                            <span class="hidden sm:inline">*</span>
                        </label>
                        <button type="button" @click="removeField(index)" class="text-xs text-red-400 hover:text-red-300" title="Hapus field">✕</button>
                    </div>
                </div>
            </template>

            <p x-show="fields.length === 0" class="rounded-lg border border-dashed border-slate-700 p-3 text-center text-xs text-slate-500">
                Belum ada field. Klik "+ Field" untuk menambah (produk tanpa field tidak meminta input tambahan).
            </p>
        </div>

        {{-- JSON final disubmit lewat textarea tersembunyi --}}
        <textarea name="input_fields" x-text="JSON.stringify(fields.filter(f => f.name !== ''), null, 2)" class="sr-only" aria-hidden="true"></textarea>

        <details class="mt-3">
            <summary class="cursor-pointer text-xs font-medium text-primary-light">Lihat JSON</summary>
            <pre class="mt-2 max-h-40 overflow-auto rounded-lg bg-slate-950 p-3 text-[11px] text-slate-400" x-text="JSON.stringify(fields.filter(f => f.name !== ''), null, 2)"></pre>
        </details>
    </section>

    <div class="flex flex-wrap gap-4">
        <label class="flex items-center gap-2 text-sm text-slate-300">
            <input type="checkbox" name="is_popular" value="1" @checked(old('is_popular', $product?->is_popular)) class="rounded border-slate-600 bg-slate-800 text-primary focus:ring-primary">
            Tampilkan sebagai populer
        </label>

        <label class="flex items-center gap-2 text-sm text-slate-300">
            <input type="checkbox" name="is_active" value="1" @checked(old('is_active', $product?->is_active ?? true)) class="rounded border-slate-600 bg-slate-800 text-primary focus:ring-primary">
            Aktif
        </label>
    </div>

    @error('name')<p class="text-xs text-red-400">{{ $message }}</p>@enderror
    @error('slug')<p class="text-xs text-red-400">{{ $message }}</p>@enderror
    @error('category_id')<p class="text-xs text-red-400">{{ $message }}</p>@enderror

    <div class="flex gap-2 pt-2">
        <button type="submit" class="btn-primary">{{ $product ? 'Simpan Perubahan' : 'Buat Produk' }}</button>
        <a href="{{ route('admin.products.index') }}" class="rounded-lg border border-slate-700 px-4 py-3 text-sm font-medium text-slate-300 transition hover:border-slate-500">Batal</a>
    </div>
</form>
