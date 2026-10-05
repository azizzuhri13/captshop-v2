@php
    $category = $category ?? null;
    $parents = $parents ?? collect();
@endphp

<form
    action="{{ $category ? route('admin.categories.update', $category) : route('admin.categories.store') }}"
    method="POST"
    class="space-y-4"
>
    @csrf
    @if ($category)
        @method('PUT')
    @endif

    <div class="grid gap-4 sm:grid-cols-2">
        <div>
            <label class="mb-1.5 block text-sm font-medium text-slate-300">Nama *</label>
            <input type="text" name="name" value="{{ old('name', $category?->name) }}" required class="input-dark" placeholder="Games">
        </div>

        <div>
            <label class="mb-1.5 block text-sm font-medium text-slate-300">Slug *</label>
            <input type="text" name="slug" value="{{ old('slug', $category?->slug) }}" required class="input-dark" placeholder="games">
        </div>

        <div>
            <label class="mb-1.5 block text-sm font-medium text-slate-300">Kategori Induk</label>
            <select name="parent_id" class="input-dark">
                <option value="">— Tidak ada (utama) —</option>
                @foreach ($parents as $id => $name)
                    <option value="{{ $id }}" @selected(old('parent_id', $category?->parent_id) == $id)>{{ $name }}</option>
                @endforeach
            </select>
        </div>

        <div>
            <label class="mb-1.5 block text-sm font-medium text-slate-300">Ikon (emoji atau URL gambar)</label>
            <input type="text" name="icon" value="{{ old('icon', $category?->icon) }}" class="input-dark" placeholder="🎮">
        </div>

        <div>
            <label class="mb-1.5 block text-sm font-medium text-slate-300">Banner (URL)</label>
            <input type="text" name="banner" value="{{ old('banner', $category?->banner) }}" class="input-dark" placeholder="https://...">
        </div>

        <div>
            <label class="mb-1.5 block text-sm font-medium text-slate-300">Urutan</label>
            <input type="number" name="sort_order" min="0" max="9999" value="{{ old('sort_order', $category?->sort_order ?? 0) }}" class="input-dark">
        </div>
    </div>

    <div>
        <label class="mb-1.5 block text-sm font-medium text-slate-300">Deskripsi</label>
        <textarea name="description" rows="3" class="input-dark" placeholder="Deskripsi singkat kategori (opsional)">{{ old('description', $category?->description) }}</textarea>
    </div>

    <label class="flex items-center gap-2 text-sm text-slate-300">
        <input type="checkbox" name="is_active" value="1" @checked(old('is_active', $category?->is_active ?? true)) class="rounded border-slate-600 bg-slate-800 text-primary focus:ring-primary">
        Aktif
    </label>

    @error('name')<p class="text-xs text-red-400">{{ $message }}</p>@enderror
    @error('slug')<p class="text-xs text-red-400">{{ $message }}</p>@enderror

    <div class="flex gap-2 pt-2">
        <button type="submit" class="btn-primary">{{ $category ? 'Simpan Perubahan' : 'Buat Kategori' }}</button>
        <a href="{{ route('admin.categories.index') }}" class="rounded-lg border border-slate-700 px-4 py-3 text-sm font-medium text-slate-300 transition hover:border-slate-500">Batal</a>
    </div>
</form>
