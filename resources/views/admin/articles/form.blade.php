@extends('layouts.admin')

@section('title', $article->exists ? 'Edit Artikel' : 'Tulis Artikel Baru')
@section('page-title', $article->exists ? 'Edit Artikel' : 'Tulis Artikel Baru')

@section('content')
    <div class="max-w-4xl rounded-xl border border-slate-800 bg-slate-800/40 p-6">
        <form
            action="{{ $article->exists ? route('admin.articles.update', $article) : route('admin.articles.store') }}"
            method="POST"
            enctype="multipart/form-data"
            class="space-y-6"
        >
            @csrf
            @if ($article->exists)
                @method('PUT')
            @endif

            <div class="space-y-4">
                {{-- Judul Artikel --}}
                <div>
                    <label class="mb-1.5 block text-sm font-medium text-slate-300">Judul Artikel *</label>
                    <input type="text" name="title" value="{{ old('title', $article->title) }}" required class="input-dark text-base" placeholder="Contoh: Bocoran Event Diamond Kuning MLBB 2026">
                    @error('title')
                        <p class="mt-1 text-xs text-red-400">{{ $message }}</p>
                    @enderror
                </div>

                <div class="grid gap-4 sm:grid-cols-2">
                    {{-- Kategori --}}
                    <div>
                        <label class="mb-1.5 block text-sm font-medium text-slate-300">Kategori</label>
                        <select name="category" class="input-dark">
                            @foreach ($categories as $cat)
                                <option value="{{ $cat }}" @selected(old('category', $article->category) === $cat)>{{ $cat }}</option>
                            @endforeach
                        </select>
                    </div>

                    {{-- Game Terkait (In-Article Topup Widget) --}}
                    <div>
                        <label class="mb-1.5 block text-sm font-medium text-slate-300">Game Terkait (In-Article Widget)</label>
                        <select name="related_product_id" class="input-dark">
                            <option value="">— Tidak Ada (Artikel Umum) —</option>
                            @foreach ($products as $prod)
                                <option value="{{ $prod->id }}" @selected(old('related_product_id', $article->related_product_id) == $prod->id)>
                                    {{ $prod->name }}
                                </option>
                            @endforeach
                        </select>
                        <p class="mt-1 text-[11px] text-slate-500">Otomatis memasang tombol top-up game ini di dalam artikel.</p>
                    </div>
                </div>

                {{-- Thumbnail Cover Image --}}
                <div>
                    <label class="mb-1.5 block text-sm font-medium text-slate-300">Gambar Cover / Thumbnail (Rasio 16:9)</label>

                    @if ($article->thumbnailUrl())
                        <div class="mb-2">
                            <img src="{{ $article->thumbnailUrl() }}" alt="{{ $article->title }}" class="h-28 w-48 rounded-xl border border-slate-700 object-cover">
                            <p class="mt-1 text-[11px] text-slate-500">Thumbnail saat ini — pilih file baru jika ingin mengganti.</p>
                        </div>
                    @endif

                    <input
                        type="file"
                        name="thumbnail"
                        accept="image/jpeg,image/png,image/webp"
                        class="block w-full cursor-pointer rounded-lg border border-slate-700 bg-slate-800 text-sm text-slate-300 file:mr-3 file:cursor-pointer file:rounded-l-lg file:border-0 file:bg-primary file:px-4 file:py-2.5 file:text-sm file:font-semibold file:text-white hover:border-primary"
                    >
                    <p class="mt-1 text-[11px] text-slate-500">Format: JPEG, PNG, atau WebP (disarankan 1200x675 px, maksimal 2MB).</p>
                    @error('thumbnail')
                        <p class="mt-1 text-xs text-red-400">{{ $message }}</p>
                    @enderror
                </div>

                {{-- Ringkasan / Excerpt --}}
                <div>
                    <label class="mb-1.5 block text-sm font-medium text-slate-300">Ringkasan / Excerpt (Meta Deskripsi SEO)</label>
                    <textarea name="excerpt" rows="2" class="input-dark text-xs" placeholder="Tuliskan 1-2 kalimat ringkasan artikel untuk preview cuplikan Google dan WhatsApp...">{{ old('excerpt', $article->excerpt) }}</textarea>
                </div>

                {{-- Konten Artikel (Body HTML / Markdown) --}}
                <div>
                    <label class="mb-1.5 block text-sm font-medium text-slate-300">Isi Konten Artikel *</label>
                    <textarea name="body" rows="15" required class="input-dark font-mono text-sm leading-relaxed" placeholder="<p>Tulis artikel lengkap di sini. Mendukung tag HTML standar seperti &lt;h2&gt;, &lt;p&gt;, &lt;ul&gt;, &lt;li&gt;, &lt;blockquote&gt;...</p>">{{ old('body', $article->body) }}</textarea>
                    <p class="mt-1 text-[11px] text-slate-500">Mendukung format paragraf, subheading &lt;h2&gt;, &lt;h3&gt;, gambar &lt;img&gt;, daftar list, dan bold.</p>
                    @error('body')
                        <p class="mt-1 text-xs text-red-400">{{ $message }}</p>
                    @enderror
                </div>

                {{-- Custom Slug (Opsional) --}}
                <div>
                    <label class="mb-1.5 block text-sm font-medium text-slate-300">Custom URL Slug (Opsional)</label>
                    <input type="text" name="slug" value="{{ old('slug', $article->slug) }}" class="input-dark text-xs" placeholder="Otomatis di-generate dari judul jika dikosongkan">
                </div>

                {{-- Status Publikasi --}}
                <div class="rounded-xl border border-slate-700 bg-slate-800/60 p-4">
                    <label class="flex items-center gap-2 text-sm font-semibold text-slate-200 cursor-pointer">
                        <input type="checkbox" name="is_published" value="1" @checked(old('is_published', $article->is_published)) class="rounded border-slate-600 bg-slate-800 text-primary focus:ring-primary h-4 w-4">
                        <span>Publikasikan Artikel Sekarang</span>
                    </label>
                    <p class="mt-1 text-xs text-slate-400 ml-6">Jika tidak dicentang, artikel akan tersimpan sebagai draft dan tidak terlihat oleh publik.</p>
                </div>
            </div>

            <div class="flex items-center gap-3 pt-2">
                <button type="submit" class="btn-primary !px-6 !py-2.5 text-sm font-bold">
                    {{ $article->exists ? 'Simpan Perubahan' : 'Terbitkan Artikel' }}
                </button>
                <a href="{{ route('admin.articles.index') }}" class="rounded-lg border border-slate-700 px-4 py-2.5 text-sm font-medium text-slate-300 hover:border-slate-500 transition">
                    Batal
                </a>
            </div>
        </form>
    </div>
@endsection
