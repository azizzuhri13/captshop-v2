<?php

namespace App\Http\Controllers\Frontend;

use App\Http\Controllers\Controller;
use App\Models\Article;
use App\Models\Category;
use App\Models\Product;
use Illuminate\Http\Response;

class SitemapController extends Controller
{
    /**
     * Sitemap XML dinamis: homepage, kategori, produk, artikel, dan halaman statis.
     */
    public function index(): Response
    {
        $categories = Category::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->get(['slug', 'updated_at']);

        $products = Product::query()
            ->where('is_active', true)
            ->whereHas('category', fn ($query) => $query->where('is_active', true))
            ->with('category:id,slug')
            ->orderByDesc('updated_at')
            ->get(['id', 'slug', 'category_id', 'updated_at']);

        $articles = Article::query()
            ->where('is_published', true)
            ->whereNotNull('published_at')
            ->where('published_at', '<=', now())
            ->orderByDesc('updated_at')
            ->get(['slug', 'updated_at']);

        return response()
            ->view('frontend.sitemap', compact('categories', 'products', 'articles'))
            ->header('Content-Type', 'application/xml; charset=UTF-8')
            ->header('Cache-Control', 'public, max-age=3600');
    }
}
