<?php

namespace Tests\Feature\Frontend;

use App\Models\Category;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SitemapSeoTest extends TestCase
{
    use RefreshDatabase;

    public function test_sitemap_contains_home_categories_products_and_static_pages(): void
    {
        $category = Category::create(['name' => 'Games', 'slug' => 'games', 'is_active' => true]);

        Product::create([
            'category_id' => $category->id,
            'name' => 'Mobile Legends',
            'slug' => 'mobile-legends',
            'is_active' => true,
        ]);

        $response = $this->get('/sitemap.xml');

        $response->assertOk()->assertHeader('Content-Type', 'application/xml; charset=UTF-8');

        $content = $response->getContent();

        $this->assertStringContainsString('<urlset', $content);
        $this->assertStringContainsString(route('home'), $content);
        $this->assertStringContainsString(route('category.show', 'games'), $content);
        $this->assertStringContainsString(route('product.show', ['games', 'mobile-legends']), $content);
        $this->assertStringContainsString(route('pages.faq'), $content);
        $this->assertStringContainsString(route('pages.about'), $content);
        $this->assertStringContainsString(route('pages.terms'), $content);
        $this->assertStringContainsString(route('pages.privacy'), $content);
    }

    public function test_sitemap_excludes_inactive_entries(): void
    {
        $category = Category::create(['name' => 'Games', 'slug' => 'games', 'is_active' => true]);
        $categoryInactive = Category::create(['name' => 'Voucher', 'slug' => 'voucher', 'is_active' => false]);

        Product::create([
            'category_id' => $category->id,
            'name' => 'Mobile Legends',
            'slug' => 'mobile-legends',
            'is_active' => true,
        ]);

        Product::create([
            'category_id' => $category->id,
            'name' => 'Free Fire',
            'slug' => 'free-fire',
            'is_active' => false,
        ]);

        $content = $this->get('/sitemap.xml')->getContent();

        $this->assertStringContainsString('mobile-legends', $content);
        $this->assertStringNotContainsString('free-fire', $content);
        $this->assertStringNotContainsString('voucher', $content);
    }

    public function test_product_page_renders_meta_og_and_json_ld(): void
    {
        $category = Category::create(['name' => 'Games', 'slug' => 'games', 'is_active' => true]);

        Product::create([
            'category_id' => $category->id,
            'name' => 'Mobile Legends',
            'slug' => 'mobile-legends',
            'is_active' => true,
        ])->items()->create([
            'name' => '86 Diamonds',
            'buy_price' => '13000',
            'sell_price' => '15000',
            'is_active' => true,
        ]);

        $response = $this->get('/games/mobile-legends');

        $response->assertOk();

        $content = $response->getContent();

        // Meta tags dinamis.
        $this->assertStringContainsString('<meta property="og:title"', $content);
        $this->assertStringContainsString('Top Up Termurah', $content);
        $this->assertStringContainsString('<meta name="twitter:card"', $content);
        $this->assertStringContainsString('<link rel="canonical"', $content);

        // Schema.org JSON-LD.
        $this->assertStringContainsString('"@type":"Product"', $content);
        $this->assertStringContainsString('"@type":"BreadcrumbList"', $content);
        $this->assertStringContainsString('"priceCurrency":"IDR"', $content);
    }
}
