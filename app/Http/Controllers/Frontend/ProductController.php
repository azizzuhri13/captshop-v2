<?php

namespace App\Http\Controllers\Frontend;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\PaymentChannel;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ProductController extends Controller
{
    /**
     * Halaman kategori: daftar produk dalam kategori (parent beserta child).
     */
    public function category(Request $request, string $slug): View
    {
        $category = Category::query()
            ->where('is_active', true)
            ->where('slug', $slug)
            ->with(['children' => fn ($query) => $query->where('is_active', true)->orderBy('sort_order')])
            ->firstOrFail();

        $categoryIds = [$category->id, ...$category->children->pluck('id')];

        $products = Product::query()
            ->where('is_active', true)
            ->whereIn('category_id', $categoryIds)
            ->with(['category:id,slug,name', 'items' => fn ($query) => $query->where('is_active', true)->orderBy('sort_order')])
            ->when($request->input('sort'), function ($query, $sort) {
                $query->when($sort === 'popular', fn ($q) => $q->orderByDesc('is_popular'))
                    ->when($sort === 'newest', fn ($q) => $q->orderByDesc('created_at'))
                    ->when($sort === 'name', fn ($q) => $q->orderBy('name'));
            }, fn ($query) => $query->orderByDesc('is_popular')->orderBy('sort_order'))
            ->paginate(24)
            ->withQueryString();

        return view('frontend.category', compact('category', 'products'));
    }

    /**
     * Halaman detail produk: single-page checkout (data, nominal, pembayaran, kontak).
     */
    public function show(string $categorySlug, string $productSlug): View
    {
        $product = Product::query()
            ->where('is_active', true)
            ->where('slug', $productSlug)
            ->whereRelation('category', 'slug', $categorySlug)
            ->with([
                'category:id,slug,name',
                'items' => fn ($query) => $query->where('is_active', true)->orderBy('sort_order'),
            ])
            ->firstOrFail();

        $paymentChannels = PaymentChannel::query()
            ->where('is_active', true)
            ->where('gateway', config('payment.default'))
            ->orderBy('sort_order')
            ->get()
            ->groupBy('group');

        return view('frontend.product', compact('product', 'paymentChannels'));
    }
}
