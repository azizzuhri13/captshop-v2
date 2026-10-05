<?php

namespace App\Http\Controllers\Frontend;

use App\Http\Controllers\Controller;
use App\Models\Banner;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductItem;
use Illuminate\Http\Request;
use Illuminate\View\View;

class HomeController extends Controller
{
    /**
     * Halaman utama: banner carousel, kategori, produk populer, flash sale.
     */
    public function index(): View
    {
        $banners = Banner::query()
            ->where('is_active', true)
            ->where('type', 'carousel')
            ->where(function ($query) {
                $query->whereNull('start_at')->orWhere('start_at', '<=', now());
            })
            ->where(function ($query) {
                $query->whereNull('end_at')->orWhere('end_at', '>=', now());
            })
            ->orderBy('sort_order')
            ->get();

        $categories = Category::query()
            ->where('is_active', true)
            ->whereNull('parent_id')
            ->withCount(['products' => fn ($query) => $query->where('is_active', true)])
            ->orderBy('sort_order')
            ->get();

        $popularProducts = Product::query()
            ->where('is_active', true)
            ->where('is_popular', true)
            ->with(['category:id,slug,name', 'items' => fn ($query) => $query->where('is_active', true)->orderBy('sort_order')])
            ->orderBy('sort_order')
            ->take(12)
            ->get();

        $flashSaleItems = ProductItem::query()
            ->where('is_active', true)
            ->whereNotNull('flash_sale_price')
            ->where('flash_sale_start', '<=', now())
            ->where('flash_sale_end', '>=', now())
            ->whereColumn('flash_sale_price', '<', 'sell_price')
            ->with('product:id,name,slug,icon,category_id', 'product.category:id,slug')
            ->orderBy('flash_sale_end')
            ->take(10)
            ->get();

        return view('frontend.home', compact('banners', 'categories', 'popularProducts', 'flashSaleItems'));
    }

    /**
     * Pencarian produk untuk navbar (JSON, dipakai autocomplete).
     */
    public function search(Request $request)
    {
        $query = trim((string) $request->query('q', ''));

        if (mb_strlen($query) < 2) {
            return response()->json(['results' => []]);
        }

        $products = Product::query()
            ->where('is_active', true)
            ->where(fn ($q) => $q->where('name', 'like', "%{$query}%")->orWhere('slug', 'like', "%{$query}%"))
            ->with('category:id,slug,name')
            ->orderByDesc('is_popular')
            ->limit(8)
            ->get(['id', 'name', 'slug', 'icon', 'is_popular', 'category_id']);

        return response()->json([
            'results' => $products->map(fn ($product) => [
                'name' => $product->name,
                'icon' => $product->icon,
                'url' => route('product.show', [$product->category->slug, $product->slug]),
            ]),
        ]);
    }
}
