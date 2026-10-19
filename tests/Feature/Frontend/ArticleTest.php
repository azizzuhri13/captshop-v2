<?php

namespace Tests\Feature\Frontend;

use App\Models\Article;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ArticleTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_can_view_articles_index(): void
    {
        $published = Article::factory()->create(['title' => 'Tips Game MLBB', 'is_published' => true, 'published_at' => now()->subHour()]);
        $draft = Article::factory()->draft()->create(['title' => 'Draft Rahasia']);

        $response = $this->get(route('articles.index'));

        $response->assertStatus(200);
        $response->assertSee('Tips Game MLBB');
        $response->assertDontSee('Draft Rahasia');
    }

    public function test_public_can_view_published_article(): void
    {
        $category = Category::create(['name' => 'Games', 'slug' => 'games']);
        $product = Product::create(['category_id' => $category->id, 'name' => 'Mobile Legends', 'slug' => 'mobile-legends']);
        ProductItem::create(['product_id' => $product->id, 'name' => '86 Diamonds', 'buy_price' => 13000, 'sell_price' => 15000]);

        $article = Article::factory()->create([
            'title' => 'Panduan Meta MLBB',
            'slug' => 'panduan-meta-mlbb',
            'is_published' => true,
            'published_at' => now()->subDay(),
            'related_product_id' => $product->id,
            'body' => '<p>Konten panduan game terlengkap.</p>',
        ]);

        $response = $this->get(route('articles.show', $article->slug));

        $response->assertStatus(200);
        $response->assertSee('Panduan Meta MLBB');
        $response->assertSee('REKOMENDASI CAPT');
        $response->assertSee('Mobile Legends');
    }

    public function test_draft_article_returns_404_to_public(): void
    {
        $draft = Article::factory()->draft()->create(['slug' => 'draft-article']);

        $response = $this->get(route('articles.show', $draft->slug));

        $response->assertStatus(404);
    }
}
