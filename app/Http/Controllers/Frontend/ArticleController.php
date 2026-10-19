<?php

namespace App\Http\Controllers\Frontend;

use App\Http\Controllers\Controller;
use App\Models\Article;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ArticleController extends Controller
{
    public function index(Request $request): View
    {
        $baseQuery = Article::query()
            ->where('is_published', true)
            ->whereNotNull('published_at')
            ->where('published_at', '<=', now())
            ->with(['author:id,name', 'relatedProduct:id,name,slug,category_id', 'relatedProduct.category:id,slug']);

        $category = $request->query('kategori');
        $search = $request->query('q');

        if ($category) {
            $baseQuery->where('category', $category);
        }

        if ($search) {
            $baseQuery->where(function ($q) use ($search) {
                $q->where('title', 'like', "%{$search}%")
                  ->orWhere('excerpt', 'like', "%{$search}%")
                  ->orWhere('category', 'like', "%{$search}%");
            });
        }

        $featured = null;
        if (! $category && ! $search) {
            $featured = (clone $baseQuery)->latest('published_at')->first();
        }

        $articlesQuery = clone $baseQuery;
        if ($featured) {
            $articlesQuery->where('id', '!=', $featured->id);
        }

        $articles = $articlesQuery->latest('published_at')->paginate(9)->withQueryString();

        $categories = Article::query()
            ->where('is_published', true)
            ->whereNotNull('category')
            ->distinct()
            ->pluck('category');

        return view('frontend.articles.index', compact('articles', 'featured', 'categories', 'category', 'search'));
    }

    public function show(string $slug): View
    {
        $article = Article::query()
            ->where('slug', $slug)
            ->where('is_published', true)
            ->whereNotNull('published_at')
            ->where('published_at', '<=', now())
            ->with([
                'author:id,name',
                'relatedProduct' => fn ($q) => $q->where('is_active', true)->with('category:id,slug'),
                'relatedProduct.items' => fn ($q) => $q->where('is_active', true)->orderBy('sell_price'),
            ])
            ->firstOrFail();

        // Increment view count secara aman per session tanpa write lock berulang
        if (! session()->has('viewed_article_' . $article->id)) {
            session()->put('viewed_article_' . $article->id, true);
            if (function_exists('defer')) {
                defer(fn () => $article->incrementQuietly('views_count'));
            } else {
                $article->incrementQuietly('views_count');
            }
        }

        $relatedArticles = Article::query()
            ->where('id', '!=', $article->id)
            ->where('is_published', true)
            ->whereNotNull('published_at')
            ->where('published_at', '<=', now())
            ->when($article->category, fn ($q) => $q->where('category', $article->category))
            ->latest('published_at')
            ->limit(3)
            ->get();

        return view('frontend.articles.show', compact('article', 'relatedArticles'));
    }
}
