<?php

namespace Tests\Feature\Supplier;

use App\Models\Category;
use App\Models\PaymentChannel;
use App\Models\Product;
use App\Models\ProductItem;
use App\Services\Notification\TelegramAlertService;
use App\Services\OrderService;
use App\Services\Payment\PaymentGatewayInterface;
use App\Support\DigiflazzCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Aturan yang diuji: item DigiFlazz hanya boleh aktif kalau snapshot pricelist
 * SEGAR menyatakan SKU persis itu ada dan kedua flag supplier true. Tanpa
 * snapshot segar, status item tidak boleh berubah dan checkout Digi ditahan.
 */
class DigiflazzAvailabilitySyncTest extends TestCase
{
    use RefreshDatabase;

    private string $catalogPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->catalogPath = storage_path('framework/testing/digiflazz_catalog.json');

        config([
            'supplier.digiflazz.pricelist.cache_path' => $this->catalogPath,
            'supplier.digiflazz.username' => 'test-user',
            'supplier.digiflazz.api_key' => 'test-key',
            'supplier.digiflazz.base_url' => 'https://api.digiflazz.test/v1',
        ]);

        @unlink($this->catalogPath);

        $this->mock(TelegramAlertService::class)->shouldReceive('send')->andReturnNull();
    }

    protected function tearDown(): void
    {
        @unlink($this->catalogPath);

        parent::tearDown();
    }

    private function makeItem(string $sku, bool $active, string $supplierType = 'digiflazz'): ProductItem
    {
        $category = Category::firstOrCreate(['slug' => 'games'], ['name' => 'Games']);
        $product = Product::firstOrCreate(
            ['slug' => 'mobile-legends'],
            ['category_id' => $category->id, 'name' => 'Mobile Legends', 'supplier_type' => $supplierType]
        );

        return ProductItem::create([
            'product_id' => $product->id,
            'name' => '296 Diamonds',
            'supplier_item_code' => $sku,
            'buy_price' => '50000',
            'sell_price' => '55000',
            'is_active' => $active,
        ]);
    }

    /** @return array<string, mixed> */
    private function row(string $sku, bool $buyer, bool $seller, int $price = 54388, string $sellerName = 'GAMEPEDIA'): array
    {
        return [
            'product_name' => 'MOBILE LEGENDS 296 Diamond',
            'buyer_sku_code' => $sku,
            'price' => $price,
            'buyer_product_status' => $buyer,
            'seller_product_status' => $seller,
            'seller_name' => $sellerName,
        ];
    }

    private function fakePricelist(array $rows): void
    {
        Http::fake(['*price-list*' => Http::response(['data' => $rows])]);
    }

    /** Beberapa response pricelist berurutan, satu per pemanggilan API. */
    private function fakePricelistQueue(array ...$responses): void
    {
        $queue = $responses;
        Http::fake(['*price-list*' => function () use (&$queue) {
            return Http::response(['data' => array_shift($queue)]);
        }]);
    }

    private function fakeRateLimit(): void
    {
        Http::fake(['*price-list*' => Http::response([
            'data' => ['rc' => '83', 'message' => 'Anda telah mencapai limitasi pengecekan pricelist'],
        ])]);
    }

    /** Cache format lama: array polos tanpa metadata `fetched_at`. */
    private function writeLegacyCatalog(array $rows, int $ageSeconds = 0): void
    {
        @mkdir(dirname($this->catalogPath), 0775, true);
        file_put_contents($this->catalogPath, json_encode($rows));
        touch($this->catalogPath, time() - $ageSeconds);
    }

    /** Envelope seperti yang ditulis pengambilan live yang sukses. */
    private function writeSnapshot(array $rows, int $ageSeconds = 0): void
    {
        @mkdir(dirname($this->catalogPath), 0775, true);
        file_put_contents($this->catalogPath, json_encode([
            'version' => 1,
            'source' => 'live',
            'fetched_at' => now()->subSeconds($ageSeconds)->toIso8601String(),
            'count' => count($rows),
            'items' => $rows,
        ]));
    }

    public function test_fresh_catalog_activates_exact_sku_when_both_flags_true(): void
    {
        $item = $this->makeItem('ml296', false);
        $this->fakePricelist([$this->row('ml296', true, true)]);

        $this->artisan('supplier:sync-digiflazz')->assertExitCode(0);

        $item->refresh();
        $this->assertTrue($item->is_active);
        $this->assertSame('54388.00', $item->buy_price);
        $this->assertSame('58200.00', $item->sell_price); // ceil(54388*1.07/100)*100
        $this->assertTrue(DigiflazzCatalog::isFresh());
    }

    public function test_any_false_flag_deactivates_item(): void
    {
        $buyerOff = $this->makeItem('ml296', true);
        $sellerOff = $this->makeItem('ml297', true);
        $this->fakePricelist([
            $this->row('ml296', false, true),
            $this->row('ml297', true, false),
        ]);

        $this->artisan('supplier:sync-digiflazz')->assertExitCode(0);

        $this->assertFalse($buyerOff->refresh()->is_active);
        $this->assertFalse($sellerOff->refresh()->is_active);
    }

    public function test_missing_sku_deactivates_item(): void
    {
        $item = $this->makeItem('ml296', true);
        $this->fakePricelist([$this->row('ml999', true, true)]);

        $this->artisan('supplier:sync-digiflazz')->assertExitCode(0);

        $this->assertFalse($item->refresh()->is_active);
    }

    public function test_recovery_reactivates_previously_deactivated_item(): void
    {
        $item = $this->makeItem('ml296', false);
        $this->fakePricelistQueue(
            [$this->row('ml296', false, false)],
            [$this->row('ml296', true, true)],
        );
        $this->artisan('supplier:sync-digiflazz')->assertExitCode(0);
        $this->assertFalse($item->refresh()->is_active);

        $this->artisan('supplier:sync-digiflazz')->assertExitCode(0);
        $this->assertTrue($item->refresh()->is_active);
    }

    public function test_blocked_seller_is_deactivated_and_never_reactivated(): void
    {
        $active = $this->makeItem('ml296', true);
        $inactive = $this->makeItem('ml297', false);
        $this->fakePricelist([
            $this->row('ml296', true, true, 54388, 'CUAN AE'),
            $this->row('ml297', true, true, 54388, 'CUAN AE'),
        ]);

        $this->artisan('supplier:sync-digiflazz')->assertExitCode(0);

        $active->refresh();
        $this->assertFalse($active->is_active);
        // Item seller diblokir tidak boleh ikut diperbarui harganya.
        $this->assertSame('50000.00', $active->buy_price);
        $this->assertFalse($inactive->refresh()->is_active);
    }

    public function test_row_without_status_flags_deactivates_item(): void
    {
        $item = $this->makeItem('ml296', true);
        Http::fake(['*price-list*' => Http::response(['data' => [[
            'buyer_sku_code' => 'ml296',
            'price' => 54388,
            'seller_name' => 'GAMEPEDIA',
        ]]])]);

        $this->artisan('supplier:sync-digiflazz')->assertExitCode(0);

        $this->assertFalse($item->refresh()->is_active);
    }

    public function test_blocked_seller_config_defaults_to_cuan_ae_and_can_be_overridden(): void
    {
        $this->assertSame(['CUAN AE'], DigiflazzCatalog::blockedSellers());

        // Override eksplisit (mis. dari .env produksi) tetap dihormati.
        config(['supplier.digiflazz.blocked_sellers' => 'cuan ae, Other  Seller']);
        $this->assertTrue(DigiflazzCatalog::sellerBlocked('Cuan Ae'));
        $this->assertTrue(DigiflazzCatalog::sellerBlocked('OTHER SELLER'));
        $this->assertFalse(DigiflazzCatalog::sellerBlocked('GAMEPEDIA'));
    }

    public function test_rate_limited_provider_aborts_without_touching_item_state(): void
    {
        $active = $this->makeItem('ml296', true);
        $inactive = $this->makeItem('ml297', false);
        $this->fakeRateLimit();

        $this->artisan('supplier:sync-digiflazz')->assertExitCode(1);

        $this->assertTrue($active->refresh()->is_active);
        $this->assertFalse($inactive->refresh()->is_active);
        $this->assertFalse(DigiflazzCatalog::isFresh(), 'RC83 tidak boleh memperbarui penanda kesegaran.');
    }

    public function test_stale_cache_is_never_used_as_availability_proof(): void
    {
        $item = $this->makeItem('ml296', false);
        // Cache 3 jam berisi SKU "tersedia" — tetap tidak boleh mengaktifkan.
        $this->writeSnapshot([$this->row('ml296', true, true)], 3 * 3600);
        $this->fakeRateLimit();

        $this->artisan('supplier:sync-digiflazz')->assertExitCode(1);

        $this->assertFalse($item->refresh()->is_active);
        $this->assertFalse(DigiflazzCatalog::isFresh());
    }

    public function test_cache_with_fresh_mtime_is_still_not_availability_proof_when_provider_fails(): void
    {
        $item = $this->makeItem('ml296', false);
        $this->writeLegacyCatalog([$this->row('ml296', true, true)], 0);
        Http::fake(['*price-list*' => Http::response('gateway timeout', 504)]);

        $this->artisan('supplier:sync-digiflazz')->assertExitCode(1);

        $this->assertFalse($item->refresh()->is_active);
    }

    public function test_dry_run_reports_without_persisting(): void
    {
        $item = $this->makeItem('ml296', false);
        $this->fakePricelist([$this->row('ml296', true, true)]);

        $this->artisan('supplier:sync-digiflazz', ['--dry-run' => true])->assertExitCode(0);

        $this->assertFalse($item->refresh()->is_active);
        $this->assertSame('50000.00', $item->buy_price);
    }

    public function test_digiflazz_checkout_is_blocked_until_catalog_is_fresh(): void
    {
        $item = $this->makeItem('ml296', true);
        PaymentChannel::create([
            'gateway' => 'belibayar', 'code' => 'QRIS', 'name' => 'QRIS', 'group' => 'QRIS',
            'flat_fee' => '0', 'percent_fee' => '0', 'is_active' => true,
        ]);

        $gateway = $this->mock(PaymentGatewayInterface::class);
        $gateway->shouldReceive('gatewayName')->andReturn('belibayar');
        $gateway->shouldReceive('createTransaction')->andReturnUsing(fn ($trx) => [
            'reference' => $trx->invoice_id,
            'transaction_id' => 'byrtrx_test',
            'payment_channel' => 'QRIS',
            'amount' => (int) $trx->total,
            'status' => 'pending',
            'qr_url' => 'https://link.belibayar.id/test.png',
            'expired_at' => now()->addHour()->timestamp,
        ]);

        $payload = [
            'product_item_id' => $item->id,
            'payment_channel' => 'QRIS',
            'customer_data' => ['user_id' => '12345', 'zone_id' => '6789'],
            'customer_phone' => '081234567890',
        ];

        try {
            app(OrderService::class)->place($payload);
            $this->fail('Checkout Digi harus ditahan saat katalog tidak segar.');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('sinkron', $e->getMessage());
        }

        // Snapshot segar (mis. hasil sync yang berhasil) membuka kembali checkout.
        $this->writeSnapshot([$this->row('ml296', true, true)], 0);

        $transaction = app(OrderService::class)->place($payload);
        $this->assertSame('pending', $transaction->status);
    }

    public function test_non_digiflazz_checkout_is_not_affected_by_catalog_freshness(): void
    {
        $item = $this->makeItem('', true, 'manual');
        PaymentChannel::create([
            'gateway' => 'belibayar', 'code' => 'QRIS', 'name' => 'QRIS', 'group' => 'QRIS',
            'flat_fee' => '0', 'percent_fee' => '0', 'is_active' => true,
        ]);

        $gateway = $this->mock(PaymentGatewayInterface::class);
        $gateway->shouldReceive('gatewayName')->andReturn('belibayar');
        $gateway->shouldReceive('createTransaction')->andReturnUsing(fn ($trx) => [
            'reference' => $trx->invoice_id,
            'transaction_id' => 'byrtrx_test',
            'payment_channel' => 'QRIS',
            'amount' => (int) $trx->total,
            'status' => 'pending',
            'qr_url' => 'https://link.belibayar.id/test.png',
            'expired_at' => now()->addHour()->timestamp,
        ]);

        $transaction = app(OrderService::class)->place([
            'product_item_id' => $item->id,
            'payment_channel' => 'QRIS',
            'customer_data' => ['user_id' => '12345'],
            'customer_phone' => '081234567890',
        ]);

        $this->assertSame('pending', $transaction->status);
    }

    public function test_checkout_is_blocked_when_item_seller_is_blocked(): void
    {
        $item = $this->makeItem('ml296', true);
        $this->writeSnapshot([$this->row('ml296', true, true, 54388, 'CUAN AE')]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('tidak tersedia');

        app(OrderService::class)->place([
            'product_item_id' => $item->id,
            'payment_channel' => 'QRIS',
            'customer_data' => ['user_id' => '12345'],
            'customer_phone' => '081234567890',
        ]);
    }

    public function test_legacy_sync_prices_never_activates_without_explicit_flags(): void
    {
        $item = $this->makeItem('ml296', false);
        Http::fake(['*price-list*' => Http::response(['data' => [[
            'buyer_sku_code' => 'ml296',
            'price' => 54388,
            // buyer_product_status / seller_product_status sengaja tidak ada
        ]]])]);

        $this->artisan('supplier:sync-prices')->assertExitCode(0);

        $this->assertFalse($item->refresh()->is_active);
    }
}
