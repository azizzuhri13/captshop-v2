<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Banner;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;

class BannerController extends Controller
{
    public function index(): View
    {
        $banners = Banner::query()->orderBy('sort_order')->paginate(20);

        return view('admin.banners.index', compact('banners'));
    }

    public function create(): View
    {
        return view('admin.banners.create');
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $this->validated($request);

        $validated['image'] = $this->storeImage($request);

        Banner::create($validated);

        return redirect()->route('admin.banners.index')->with('success', 'Banner berhasil dibuat.');
    }

    public function edit(Banner $banner): View
    {
        return view('admin.banners.edit', compact('banner'));
    }

    public function update(Request $request, Banner $banner): RedirectResponse
    {
        $validated = $this->validated($request, $banner->id);

        if ($request->hasFile('image')) {
            $newPath = $this->storeImage($request);

            $this->deleteImageFile($banner->image);

            $validated['image'] = $newPath;
        }

        $banner->update($validated);

        return redirect()->route('admin.banners.index')->with('success', 'Banner berhasil diperbarui.');
    }

    public function destroy(Banner $banner): RedirectResponse
    {
        $this->deleteImageFile($banner->image);

        $banner->delete();

        return redirect()->route('admin.banners.index')->with('success', 'Banner berhasil dihapus.');
    }

    protected function validated(Request $request, ?int $ignoreId = null): array
    {
        $rules = [
            'title' => ['required', 'string', 'max:150'],
            'url' => ['nullable', 'string', 'max:255', function (string $attribute, mixed $value, \Closure $fail) {
                // Hanya izinkan link http/https; skema lain (javascript:, data:)
                // berbahaya karena URL dirender langsung sebagai atribut href.
                if (is_string($value) && $value !== '' && ! preg_match('#^https?://#i', $value)) {
                    $fail('URL banner harus diawali http:// atau https://.');
                }
            }],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:9999'],
            'start_at' => ['nullable', 'date'],
            'end_at' => ['nullable', 'date', 'after_or_equal:start_at'],
            'type' => ['required', 'in:carousel,promo'],
            'is_active' => ['boolean'],
        ];

        // Saat create wajib upload; saat update bersifat opsional (pakai gambar lama).
        $rules['image'] = $ignoreId === null
            ? ['required', 'image', 'mimes:jpeg,png,jpg,webp', 'max:2048']
            : ['nullable', 'image', 'mimes:jpeg,png,jpg,webp', 'max:2048'];

        $validated = $request->validate($rules);

        $validated['is_active'] = $request->boolean('is_active');

        return $validated;
    }

    /**
     * Simpan file gambar ke storage/app/public/banners.
     * Return path relatif disk public (mis. "banners/abc.webp") untuk kolom image.
     */
    protected function storeImage(Request $request): string
    {
        return $request->file('image')->store('banners', 'public');
    }

    /**
     * Hapus file gambar lama dari disk public, kecuali berupa URL eksternal.
     */
    protected function deleteImageFile(?string $path): void
    {
        if (! $path || str_starts_with($path, 'http')) {
            return;
        }

        Storage::disk('public')->delete($path);
    }
}
