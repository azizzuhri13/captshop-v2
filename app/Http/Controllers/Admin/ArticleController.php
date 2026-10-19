<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Article;
use App\Models\Product;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\View\View;

class ArticleController extends Controller
{
    public function index(Request $request): View
    {
        $query = Article::query()->with(['author:id,name', 'relatedProduct:id,name']);

        if ($search = $request->input('q')) {
            $query->where(function ($q) use ($search) {
                $q->where('title', 'like', "%{$search}%")
                  ->orWhere('excerpt', 'like', "%{$search}%")
                  ->orWhere('category', 'like', "%{$search}%");
            });
        }

        if ($category = $request->input('category')) {
            $query->where('category', $category);
        }

        $articles = $query->orderByDesc('id')->paginate(15)->withQueryString();
        $categories = Article::query()->whereNotNull('category')->distinct()->pluck('category');

        return view('admin.articles.index', compact('articles', 'categories'));
    }

    public function create(): View
    {
        $products = Product::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']);
        $categories = ['Tips & Trik', 'Event Game', 'Bocoran & Update', 'Promo', 'Esports'];

        return view('admin.articles.form', [
            'article' => new Article(),
            'products' => $products,
            'categories' => $categories,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'slug' => ['nullable', 'string', 'max:255'],
            'category' => ['nullable', 'string', 'max:100'],
            'related_product_id' => ['nullable', 'exists:products,id'],
            'thumbnail' => ['nullable', 'image', 'mimes:jpeg,png,jpg,webp', 'max:2048'],
            'excerpt' => ['nullable', 'string', 'max:500'],
            'body' => ['required', 'string'],
            'is_published' => ['nullable', 'boolean'],
        ]);

        $slug = $this->generateUniqueSlug($validated['title'], $validated['slug'] ?? null);
        $thumbnailPath = null;

        if ($request->hasFile('thumbnail')) {
            $thumbnailPath = $request->file('thumbnail')->store('articles', 'public');
        }

        $isPublished = (bool) ($request->input('is_published', false));
        $sanitizedBody = $this->sanitizeHtml($validated['body']);
        $readingTime = max(1, (int) ceil(str_word_count(strip_tags($sanitizedBody)) / 200));

        Article::create([
            'title' => $validated['title'],
            'slug' => $slug,
            'category' => $validated['category'] ?? 'Tips & Trik',
            'related_product_id' => $validated['related_product_id'] ?? null,
            'thumbnail' => $thumbnailPath,
            'excerpt' => $validated['excerpt'] ?? Str::limit(strip_tags($sanitizedBody), 150),
            'body' => $sanitizedBody,
            'author_id' => auth('admin')->id(),
            'reading_time_minutes' => $readingTime,
            'is_published' => $isPublished,
            'published_at' => $isPublished ? now() : null,
        ]);

        return redirect()->route('admin.articles.index')->with('success', 'Artikel berhasil dibuat.');
    }

    public function edit(Article $article): View
    {
        $products = Product::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']);
        $categories = ['Tips & Trik', 'Event Game', 'Bocoran & Update', 'Promo', 'Esports'];

        return view('admin.articles.form', compact('article', 'products', 'categories'));
    }

    public function update(Request $request, Article $article): RedirectResponse
    {
        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'slug' => ['nullable', 'string', 'max:255'],
            'category' => ['nullable', 'string', 'max:100'],
            'related_product_id' => ['nullable', 'exists:products,id'],
            'thumbnail' => ['nullable', 'image', 'mimes:jpeg,png,jpg,webp', 'max:2048'],
            'excerpt' => ['nullable', 'string', 'max:500'],
            'body' => ['required', 'string'],
            'is_published' => ['nullable', 'boolean'],
        ]);

        $slug = $this->generateUniqueSlug($validated['title'], $validated['slug'] ?? null, $article->id);
        $thumbnailPath = $article->thumbnail;

        if ($request->hasFile('thumbnail')) {
            if ($article->thumbnail && Storage::disk('public')->exists($article->thumbnail)) {
                Storage::disk('public')->delete($article->thumbnail);
            }
            $thumbnailPath = $request->file('thumbnail')->store('articles', 'public');
        }

        $isPublished = (bool) ($request->input('is_published', false));
        $sanitizedBody = $this->sanitizeHtml($validated['body']);
        $readingTime = max(1, (int) ceil(str_word_count(strip_tags($sanitizedBody)) / 200));

        $publishedAt = $article->published_at;
        if ($isPublished && ! $publishedAt) {
            $publishedAt = now();
        } elseif (! $isPublished) {
            $publishedAt = null;
        }

        $article->update([
            'title' => $validated['title'],
            'slug' => $slug,
            'category' => $validated['category'] ?? $article->category,
            'related_product_id' => $validated['related_product_id'] ?? null,
            'thumbnail' => $thumbnailPath,
            'excerpt' => $validated['excerpt'] ?? Str::limit(strip_tags($sanitizedBody), 150),
            'body' => $sanitizedBody,
            'reading_time_minutes' => $readingTime,
            'is_published' => $isPublished,
            'published_at' => $publishedAt,
        ]);

        return redirect()->route('admin.articles.index')->with('success', 'Artikel berhasil diperbarui.');
    }

    public function destroy(Article $article): RedirectResponse
    {
        if ($article->thumbnail && Storage::disk('public')->exists($article->thumbnail)) {
            Storage::disk('public')->delete($article->thumbnail);
        }

        $article->delete();

        return redirect()->route('admin.articles.index')->with('success', 'Artikel berhasil dihapus.');
    }

    protected function generateUniqueSlug(string $title, ?string $customSlug = null, ?int $ignoreId = null): string
    {
        $slug = Str::slug($customSlug ?: $title);
        $original = $slug;
        $count = 1;

        while (Article::query()->where('slug', $slug)->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))->exists()) {
            $slug = "{$original}-{$count}";
            $count++;
        }

        return $slug;
    }

    protected function sanitizeHtml(string $html): string
    {
        // Bersihkan script tag dan atribut inline berbahaya
        $clean = preg_replace('#<script(.*?)>(.*?)</script>#is', '', $html);
        $clean = preg_replace('#<iframe(.*?)javascript:(.*?)</iframe>#is', '', $clean);
        $clean = preg_replace('#\s*on\w+\s*=\s*(".*?"|\'.*?\'|[^\s>]+)#is', '', $clean);
        $clean = preg_replace('#href\s*=\s*["\']javascript:[^"\']*["\']#is', 'href="#"', $clean);

        return $clean;
    }
}
