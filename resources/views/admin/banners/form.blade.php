@php
    $banner = $banner ?? null;
@endphp

<form
    action="{{ $banner ? route('admin.banners.update', $banner) : route('admin.banners.store') }}"
    method="POST"
    enctype="multipart/form-data"
    class="space-y-4"
>
    @csrf
    @if ($banner)
        @method('PUT')
    @endif

    <div class="grid gap-4 sm:grid-cols-2">
        <div class="sm:col-span-2">
            <label class="mb-1.5 block text-sm font-medium text-slate-300">Judul *</label>
            <input type="text" name="title" value="{{ old('title', $banner?->title) }}" required class="input-dark" placeholder="Promo Merdeka">
        </div>

        <div class="sm:col-span-2">
            <label class="mb-1.5 block text-sm font-medium text-slate-300">Gambar Banner {{ $banner ? '' : '*' }}</label>

            {{-- Pratinjau gambar saat edit --}}
            @if ($banner?->imageUrl())
                <div class="mb-2">
                    <img src="{{ $banner->imageUrl() }}" alt="Banner saat ini: {{ $banner->title }}" class="h-24 w-full max-w-sm rounded-lg border border-slate-700 object-cover">
                    <p class="mt-1 text-[11px] text-slate-500">Gambar saat ini — upload file baru untuk mengganti.</p>
                </div>
            @endif

            <input
                type="file"
                name="image"
                accept="image/jpeg,image/png,image/webp"
                class="block w-full cursor-pointer rounded-lg border border-slate-700 bg-slate-800 text-sm text-slate-300 file:mr-3 file:cursor-pointer file:rounded-l-lg file:border-0 file:bg-primary file:px-4 file:py-2.5 file:text-sm file:font-semibold file:text-white hover:border-primary"
                {{ $banner ? '' : 'required' }}
            >
            <p class="mt-1 text-[11px] text-slate-500">Format: JPEG/PNG/WebP, maksimal 2MB.</p>
        </div>

        <div>
            <label class="mb-1.5 block text-sm font-medium text-slate-300">Link Tujuan (opsional)</label>
            <input type="text" name="url" value="{{ old('url', $banner?->url) }}" class="input-dark" placeholder="https://...">
        </div>

        <div>
            <label class="mb-1.5 block text-sm font-medium text-slate-300">Tipe</label>
            <select name="type" class="input-dark">
                <option value="carousel" @selected(old('type', $banner?->type ?? 'carousel') === 'carousel')>Carousel (hero)</option>
                <option value="promo" @selected(old('type', $banner?->type) === 'promo')>Promo (mid-page)</option>
            </select>
        </div>

        <div>
            <label class="mb-1.5 block text-sm font-medium text-slate-300">Urutan</label>
            <input type="number" name="sort_order" min="0" max="9999" value="{{ old('sort_order', $banner?->sort_order ?? 0) }}" class="input-dark">
        </div>

        <div>
            <label class="mb-1.5 block text-sm font-medium text-slate-300">Tayang Mulai</label>
            <input type="datetime-local" name="start_at" value="{{ old('start_at', $banner?->start_at?->format('Y-m-d\TH:i')) }}" class="input-dark">
        </div>

        <div>
            <label class="mb-1.5 block text-sm font-medium text-slate-300">Tayang Selesai</label>
            <input type="datetime-local" name="end_at" value="{{ old('end_at', $banner?->end_at?->format('Y-m-d\TH:i')) }}" class="input-dark">
        </div>
    </div>

    <label class="flex items-center gap-2 text-sm text-slate-300">
        <input type="checkbox" name="is_active" value="1" @checked(old('is_active', $banner?->is_active ?? true)) class="rounded border-slate-600 bg-slate-800 text-primary focus:ring-primary">
        Aktif
    </label>

    @error('title')<p class="text-xs text-red-400">{{ $message }}</p>@enderror
    @error('image')<p class="text-xs text-red-400">{{ $message }}</p>@enderror

    <div class="flex gap-2 pt-2">
        <button type="submit" class="btn-primary">{{ $banner ? 'Simpan Perubahan' : 'Buat Banner' }}</button>
        <a href="{{ route('admin.banners.index') }}" class="rounded-lg border border-slate-700 px-4 py-3 text-sm font-medium text-slate-300 transition hover:border-slate-500">Batal</a>
    </div>
</form>
