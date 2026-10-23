<?php

namespace Tests\Feature\Console;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Regresi untuk pengamanan kesegaran data sinkronisasi DigiFlazz:
 * cache basi maupun cache hasil fallback (RC 83/API error) tidak boleh
 * mengubah katalog, nonaktifkan massal ditahan, dan cache baru harus
 * menyimpan metadata fetched_at.
 */
class SyncDigiflazzProductsTest extends TestCase
{
    use RefreshDatabase;

    private string $cacheDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->cacheDir = storage_path('app/testing/digiflazz-'.uniqid());
        File::ensureDirectoryExists($this->cacheDir);

        config([
            'supplier.digiflazz.username' => 'user-test',
            'supplier.digiflazz.api_key' => 'key-test',
            'supplier.digiflazz.base_url' => 'https://api.digiflazz.test/v1',
            'supplier.digiflazz.pricelist.cache_path' => $this->cacheDir.'/pricelist.json',
            'supplier.digiflazz.pricelist.legacy_cache_path' => $this->cacheDir.'/legacy-none.json',
            'supplier.digiflazz.pricelist.max_age_minutes' => 360,
            'supplier.digiflazz.pricelist.max_deactivation_ratio' => 0.25,
            'supplier.digiflazz.pricelist.max_deactivation_floor' => 5,
        ]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->cacheDir);

        parent::tearDown();
    }

    private function makeItem(string $sku, bool $active = true, string $buy = '13000', string $sell = '15000'): ProductItem
    {
        $category = Category::firstOrCreate(['slug' => 'games'], ['name' => 'Games']);

        $product = Product::firstOrCreate(
            ['slug' => 'mobile-legends'],
            [
                'category_id' => $category->id,
                'name' => 'Mobile Legends',
                'supplier_type' => 'digiflazz',
                'supplier_code' => 'mobile-legend',
            ],
        );

        return ProductItem::create([
            'product_id' => $product->id,
            'name' => 'Item '.$sku,
            'supplier_item_code' => $sku,
            'buy_price' => $buy,
            'sell_price' => $sell,
            'is_active' => $active,
        ]);
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     */
    private function fakeLivePricelist(array $items): void
    {
        Http::fake([
            'api.digiflazz.test/*' => Http::response(['data' => $items], 200),
        ]);
    }

    private function fakeRateLimit(): void
    {
        Http::fake([
            'api.digiflazz.test/*' => Http::response([
                'data' => ['rc' => '83', 'message' => 'Rate limit exceeded'],
            ], 200),
        ]);
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     */
    private function writeCache(array $items, ?string $fetchedAt = null, bool $envelope = true): string
    {
        $path = $this->cacheDir.'/pricelist.json';
        $payload = $envelope
            ? ['version' => 1, 'fetched_at' => $fetchedAt ?? now()->toIso8601String(), 'count' => count($items), 'items' => $items]
            : $items;

        File::put($path, json_encode($payload));

        return $path;
    }

    /**
     * @return array<string, mixed>
     */
    private function pricelistRow(string $sku, float $price, bool $buyerStatus = true, bool $sellerStatus = true): array
    {
        return [
            'product_name' => 'Produk '.$sku,
            'category' => 'Games',
            'brand' => 'Mobile Legends',
            'seller_name' => 'Seller',
            'price' => $price,
            'buyer_sku_code' => $sku,
            'buyer_product_status' => $buyerStatus,
            'seller_product_status' => $sellerStatus,
            'unlimited_stock' => true,
            'stock' => 0,
        ];
    }

    public function test_live_pricelist_updates_price_and_reactivates_item(): void
    {
        $item = $this->makeItem('ml86', active: false, buy: '13000', sell: '15000');
        $this->fakeLivePricelist([$this->pricelistRow('ml86', 20000)]);

        $this->artisan('supplier:sync-digiflazz')->assertExitCode(0);

        $item->refresh();
        $this->assertTrue($item->is_active);
        $this->assertSame('20000.00', $item->buy_price);
        // 20000 * 1.07 = 21400 -> dibulatkan ke atas kelipatan 100.
        $this->assertSame('21400.00', $item->sell_price);

        $cache = json_decode(File::get($this->cacheDir.'/pricelist.json'), true);
        $this->assertIsArray($cache['items'] ?? null, 'cache baru wajib berbentuk envelope berisi items');
        $this->assertNotEmpty($cache['fetched_at'] ?? null, 'cache wajib menyimpan metadata fetched_at');
        $this->assertSame(1, $cache['count']);
    }

    public function test_live_pricelist_deactivates_item_missing_from_pricelist(): void
    {
        $item = $this->makeItem('ml86');
        $this->fakeLivePricelist([$this->pricelistRow('ml3', 5000)]);

        $this->artisan('supplier:sync-digiflazz')->assertExitCode(0);

        $this->assertFalse($item->refresh()->is_active);
    }

    public function test_rate_limited_stale_cache_does_not_touch_catalog(): void
    {
        $item = $this->makeItem('ml86', active: false, buy: '13000', sell: '15000');

        // Cache 10 jam: masih mengklaim SKU aktif dengan harga lama.
        $this->writeCache(
            [$this->pricelistRow('ml86', 99000)],
            now()->subHours(10)->toIso8601String(),
        );
        $this->fakeRateLimit();

        $this->artisan('supplier:sync-digiflazz')
            ->expectsOutputToContain('BASI')
            ->assertExitCode(1);

        $item->refresh();
        $this->assertFalse($item->is_active, 'item tidak boleh diaktifkan dari cache basi');
        $this->assertSame('13000.00', $item->buy_price, 'harga tidak boleh diubah dari cache basi');
        $this->assertSame('15000.00', $item->sell_price);
    }

    public function test_rate_limited_fresh_cache_is_not_availability_proof(): void
    {
        $item = $this->makeItem('ml86', active: true, buy: '13000', sell: '15000');

        // 45 menit masih di bawah max_age, tapi tetap hasil fallback RC 83.
        $this->writeCache(
            [$this->pricelistRow('ml86', 20000)],
            now()->subMinutes(45)->toIso8601String(),
        );
        $this->fakeRateLimit();

        $this->artisan('supplier:sync-digiflazz')
            ->expectsOutputToContain('cache')
            ->assertExitCode(1);

        $item->refresh();
        $this->assertTrue($item->is_active);
        $this->assertSame('13000.00', $item->buy_price);
        $this->assertSame('15000.00', $item->sell_price);
    }

    public function test_legacy_bare_array_cache_older_than_limit_is_rejected(): void
    {
        $item = $this->makeItem('ml86', active: false);

        $path = $this->writeCache([$this->pricelistRow('ml86', 99000)], envelope: false);
        // Format lama tidak punya fetched_at -> umur diambil dari mtime file.
        touch($path, time() - (10 * 3600));
        $this->fakeRateLimit();

        $this->artisan('supplier:sync-digiflazz')
            ->expectsOutputToContain('BASI')
            ->assertExitCode(1);

        $this->assertFalse($item->refresh()->is_active);
    }

    public function test_mass_deactivation_guard_blocks_sync(): void
    {
        $items = [];
        for ($i = 1; $i <= 10; $i++) {
            $items[] = $this->makeItem('sku'.$i);
        }

        // Pricelist hanya memuat 1 dari 10 SKU -> indikasi data terpotong.
        $this->fakeLivePricelist([$this->pricelistRow('sku1', 20000)]);

        $this->artisan('supplier:sync-digiflazz')
            ->expectsOutputToContain('nonaktifkan massal')
            ->assertExitCode(1);

        foreach ($items as $item) {
            $this->assertTrue($item->refresh()->is_active, 'tidak ada item yang boleh dinonaktifkan saat pengaman aktif');
        }
    }

    public function test_mass_deactivation_guard_can_be_overridden_explicitly(): void
    {
        $items = [];
        for ($i = 1; $i <= 10; $i++) {
            $items[] = $this->makeItem('sku'.$i);
        }

        $this->fakeLivePricelist([$this->pricelistRow('sku1', 20000)]);

        $this->artisan('supplier:sync-digiflazz', ['--allow-mass-deactivation' => true])->assertExitCode(0);

        $this->assertTrue($items[0]->refresh()->is_active);
        for ($i = 1; $i < 10; $i++) {
            $this->assertFalse($items[$i]->refresh()->is_active);
        }
    }

    public function test_force_cache_does_not_call_api(): void
    {
        $item = $this->makeItem('ml86', active: true, buy: '13000', sell: '15000');

        $this->writeCache(
            [$this->pricelistRow('ml86', 20000)],
            now()->subMinutes(10)->toIso8601String(),
        );
        Http::fake();

        $this->artisan('supplier:sync-digiflazz', ['--force-cache' => true])->assertExitCode(0);

        Http::assertNothingSent();
        $this->assertSame('20000.00', $item->refresh()->buy_price);
    }

    public function test_unavailable_pricelist_and_missing_cache_fails_without_changes(): void
    {
        $item = $this->makeItem('ml86', active: true);
        $this->fakeRateLimit();

        $this->artisan('supplier:sync-digiflazz')
            ->expectsOutputToContain('Pricelist tidak tersedia')
            ->assertExitCode(1);

        $this->assertTrue($item->refresh()->is_active);
    }

    public function test_sku_with_zero_price_is_skipped_instead_of_setting_free_price(): void
    {
        $item = $this->makeItem('ml86', active: true, buy: '13000', sell: '15000');
        $this->fakeLivePricelist([$this->pricelistRow('ml86', 0)]);

        $this->artisan('supplier:sync-digiflazz')->assertExitCode(0);

        $item->refresh();
        $this->assertSame('13000.00', $item->buy_price);
        $this->assertSame('15000.00', $item->sell_price);
    }

    public function test_success_is_recorded_for_freshness_reporting(): void
    {
        $this->makeItem('ml86');
        $this->fakeLivePricelist([$this->pricelistRow('ml86', 20000)]);

        $this->artisan('supplier:sync-digiflazz')->assertExitCode(0);

        $marker = json_decode(File::get($this->cacheDir.'/last-success.json'), true);
        $this->assertSame('live', $marker['source']);
        $this->assertSame(0, $marker['data_age_minutes']);
        $this->assertSame(1, $marker['pricelist_items']);
    }
}
