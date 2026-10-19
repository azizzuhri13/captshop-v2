<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Category;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class CategoryController extends Controller
{
    public function index(): View
    {
        $categories = Category::query()
            ->with('parent:id,name')
            ->withCount('products')
            ->orderBy('sort_order')
            ->paginate(20);

        return view('admin.categories.index', compact('categories'));
    }

    public function create(): View
    {
        $parents = Category::query()->whereNull('parent_id')->orderBy('name')->pluck('name', 'id');

        return view('admin.categories.create', compact('parents'));
    }

    public function store(Request $request): RedirectResponse
    {
        $iconRule = $request->hasFile('icon')
            ? ['image', 'mimes:jpeg,png,jpg,webp', 'max:2048']
            : ['string', 'max:255'];

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'slug' => ['required', 'string', 'max:100', 'regex:/^[a-z0-9-]+$/', 'unique:categories,slug'],
            'parent_id' => ['nullable', 'exists:categories,id'],
            'icon' => ['nullable', ...$iconRule],
            'banner' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:9999'],
            'is_active' => ['boolean'],
        ]);

        if ($request->hasFile('icon')) {
            $path = $request->file('icon')->store('categories', 'public');
            $validated['icon'] = $path;
        }

        $validated['is_active'] = $request->boolean('is_active');

        Category::create($validated);

        return redirect()->route('admin.categories.index')->with('success', 'Kategori berhasil dibuat.');
    }

    public function edit(Category $category): View
    {
        $parents = Category::query()
            ->whereNull('parent_id')
            ->whereKeyNot($category->id)
            ->orderBy('name')
            ->pluck('name', 'id');

        return view('admin.categories.edit', compact('category', 'parents'));
    }

    public function update(Request $request, Category $category): RedirectResponse
    {
        $iconRule = $request->hasFile('icon')
            ? ['image', 'mimes:jpeg,png,jpg,webp', 'max:2048']
            : ['string', 'max:255'];

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'slug' => ['required', 'string', 'max:100', 'regex:/^[a-z0-9-]+$/', 'unique:categories,slug,'.$category->id],
            'parent_id' => ['nullable', 'exists:categories,id'],
            'icon' => ['nullable', ...$iconRule],
            'banner' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:9999'],
            'is_active' => ['boolean'],
        ]);

        if ((int) ($validated['parent_id'] ?? 0) === $category->id) {
            return back()->withErrors(['parent_id' => 'Kategori tidak bisa menjadi induk dirinya sendiri.']);
        }

        if ($request->hasFile('icon')) {
            // Delete old icon if it's a local storage file
            if ($category->icon && ! str_starts_with($category->icon, 'http')) {
                \Illuminate\Support\Facades\Storage::disk('public')->delete($category->icon);
            }
            $path = $request->file('icon')->store('categories', 'public');
            $validated['icon'] = $path;
        } else {
            // Unset icon from validated to prevent overwriting with null if no new file is uploaded
            unset($validated['icon']);
        }

        $validated['is_active'] = $request->boolean('is_active');

        $category->update($validated);

        return redirect()->route('admin.categories.index')->with('success', 'Kategori berhasil diperbarui.');
    }

    public function destroy(Category $category): RedirectResponse
    {
        if ($category->products()->exists()) {
            return back()->with('error', 'Kategori masih memiliki produk. Pindahkan/hapus produknya dahulu.');
        }

        $category->delete();

        return redirect()->route('admin.categories.index')->with('success', 'Kategori berhasil dihapus.');
    }
}
