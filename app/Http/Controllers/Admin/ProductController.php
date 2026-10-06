<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Product;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;

class ProductController extends Controller
{
    public function index(Request $request): View
    {
        $products = Product::query()
            ->with(['category:id,name', 'items:id,product_id'])
            ->when($request->input('q'), fn ($query, $q) => $query->where('name', 'like', "%{$q}%"))
            ->latest()
            ->paginate(20)
            ->withQueryString();

        return view('admin.products.index', compact('products'));
    }

    public function create(): View
    {
        $categories = Category::query()->orderBy('name')->pluck('name', 'id');

        return view('admin.products.create', compact('categories'));
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $this->validated($request);
        $validated['is_popular'] = $request->boolean('is_popular');

        $validated['icon'] = $request->hasFile('icon') ? $request->file('icon')->store('products', 'public') : null;
        $validated['banner'] = $request->hasFile('banner') ? $request->file('banner')->store('products', 'public') : null;

        Product::create($validated + ['input_fields' => $this->parseInputFields($request)]);

        return redirect()->route('admin.products.index')->with('success', 'Produk berhasil dibuat.');
    }

    public function edit(Product $product): View
    {
        $categories = Category::query()->orderBy('name')->pluck('name', 'id');

        return view('admin.products.edit', compact('product', 'categories'));
    }

    public function update(Request $request, Product $product): RedirectResponse
    {
        $validated = $this->validated($request, $product->id);
        $validated['is_popular'] = $request->boolean('is_popular');

        if ($request->hasFile('icon')) {
            $this->deleteImageFile($product->icon);

            $validated['icon'] = $request->file('icon')->store('products', 'public');
        }

        if ($request->hasFile('banner')) {
            $this->deleteImageFile($product->banner);

            $validated['banner'] = $request->file('banner')->store('products', 'public');
        }

        $product->update($validated + ['input_fields' => $this->parseInputFields($request)]);

        return redirect()->route('admin.products.index')->with('success', 'Produk berhasil diperbarui.');
    }

    public function destroy(Product $product): RedirectResponse
    {
        if ($product->items()->exists()) {
            return back()->with('error', 'Produk masih memiliki item/SKU. Hapus item-nya dahulu.');
        }

        $this->deleteImageFile($product->icon);
        $this->deleteImageFile($product->banner);

        $product->delete();

        return redirect()->route('admin.products.index')->with('success', 'Produk berhasil dihapus.');
    }

    protected function validated(Request $request, ?int $ignoreId = null): array
    {
        return $request->validate([
            'category_id' => ['required', 'exists:categories,id'],
            'name' => ['required', 'string', 'max:150'],
            'slug' => ['required', 'string', 'max:150', 'regex:/^[a-z0-9-]+$/', 'unique:products,slug'.($ignoreId ? ','.$ignoreId : '')],
            'code' => ['nullable', 'string', 'max:100'],
            'icon' => ['nullable', 'image', 'mimes:jpeg,png,jpg,webp', 'max:2048'],
            'banner' => ['nullable', 'image', 'mimes:jpeg,png,jpg,webp', 'max:2048'],
            'description' => ['nullable', 'string', 'max:2000'],
            'instruction' => ['nullable', 'string', 'max:2000'],
            'supplier_type' => ['nullable', 'string', 'max:50'],
            'supplier_code' => ['nullable', 'string', 'max:100'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:9999'],
            'is_popular' => ['boolean'],
            'is_active' => ['boolean'],
        ]);
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

    /**
     * Parse input_fields dari form admin: string JSON dari builder dinamis.
     */
    protected function parseInputFields(Request $request): ?array
    {
        $json = trim((string) $request->input('input_fields'));

        if ($json === '') {
            return null;
        }

        $decoded = json_decode($json, true);

        if (! is_array($decoded)) {
            abort(422, 'Format Input Fields tidak valid (harus JSON array).');
        }

        $fields = [];

        foreach ($decoded as $field) {
            if (! is_array($field) || empty($field['name'])) {
                continue;
            }

            $fields[] = [
                'name' => (string) $field['name'],
                'label' => (string) ($field['label'] ?? ucfirst($field['name'])),
                'type' => (string) ($field['type'] ?? 'text'),
                'required' => (bool) ($field['required'] ?? false),
                'placeholder' => (string) ($field['placeholder'] ?? ''),
            ];
        }

        return $fields;
    }
}
