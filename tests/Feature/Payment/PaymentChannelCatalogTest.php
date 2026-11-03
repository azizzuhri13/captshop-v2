<?php

namespace Tests\Feature\Payment;

use App\Models\Category;
use App\Models\PaymentChannel;
use App\Models\Product;
use App\Models\ProductItem;
use App\Services\Payment\BelibayarService;
use App\Services\Payment\PaymentGatewayInterface;
use App\Services\Payment\TripayService;
use Database\Seeders\PaymentChannelSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Kanal Tripay tidak boleh muncul sendiri di katalog publik dan seeder harus
 * bisa diulang tanpa menghapus pengaturan admin.
 */
class PaymentChannelCatalogTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    private function seedFixtureProduct(): Product
    {
        $category = Category::create(['name' => 'Games', 'slug' => 'games', 'is_active' => true]);

        $product = Product::create([
            'category_id' => $category->id,
            'name' => 'Mobile Legends',
            'slug' => 'mobile-legends',
            'is_active' => true,
        ]);

        ProductItem::create([
            'product_id' => $product->id,
            'name' => '86 Diamonds',
            'supplier_item_code' => 'ml-86',
            'buy_price' => '13000',
            'sell_price' => '15000',
            'is_active' => true,
        ]);

        return $product;
    }

    /**
     * @return list<string>
     */
    private function renderedChannelCodes(): array
    {
        $response = $this->get(route('product.show', ['category' => 'games', 'slug' => 'mobile-legends']));
        $response->assertOk();

        return $response->viewData('paymentChannels')
            ->flatten(1)
            ->pluck('code')
            ->sort()
            ->values()
            ->all();
    }

    public function test_initial_seed_creates_only_known_tripay_channels_all_inactive(): void
    {
        $this->seed(PaymentChannelSeeder::class);

        $map = config('payment.tripay.channels');
        $tripay = PaymentChannel::query()->where('gateway', 'tripay')->orderBy('code')->get();

        $this->assertNotEmpty($tripay, 'Seeder harus membuat baris kanal Tripay.');
        $this->assertCount(count($map), $tripay, 'Satu baris per kode Tripay resmi, tidak lebih.');

        foreach ($tripay as $channel) {
            $this->assertArrayHasKey($channel->code, $map, "Kode {$channel->code} tidak ada di peta resmi.");
            $this->assertFalse($channel->is_active, "Kanal baru {$channel->code} harus nonaktif.");
            $this->assertSame('0.00', (string) $channel->flat_fee);
            $this->assertSame('0.00', (string) $channel->percent_fee);
        }
    }

    public function test_repeat_seed_preserves_admin_activation_fees_and_metadata(): void
    {
        $this->seed(PaymentChannelSeeder::class);

        $channel = PaymentChannel::query()->where('gateway', 'tripay')->where('code', 'BCA')->firstOrFail();
        $channel->update([
            'name' => 'BCA VA (admin)',
            'flat_fee' => '4500',
            'percent_fee' => '0.70',
            'is_active' => true,
        ]);

        // Baris lama yang tidak lagi didukung harus dinonaktifkan oleh seeder.
        $stale = PaymentChannel::create([
            'gateway' => 'tripay', 'code' => 'STALEVA', 'name' => 'Stale VA',
            'group' => 'Virtual Account', 'is_active' => true,
        ]);
        $legacy = PaymentChannel::create([
            'gateway' => 'midtrans', 'code' => 'BCA', 'name' => 'Midtrans BCA',
            'group' => 'Virtual Account', 'is_active' => true,
        ]);

        $this->seed(PaymentChannelSeeder::class);

        $channel->refresh();
        $this->assertTrue($channel->is_active, 'Aktivasi admin tidak boleh direset seeder.');
        $this->assertSame('BCA VA (admin)', $channel->name);
        $this->assertSame('4500.00', (string) $channel->flat_fee);
        $this->assertSame('0.70', (string) $channel->percent_fee);

        $this->assertFalse($stale->refresh()->is_active, 'Kode Tripay tak dikenal harus nonaktif.');
        $this->assertFalse($legacy->refresh()->is_active, 'Gateway tak didukung harus nonaktif.');

        // Belibayar tetap lengkap dan idempoten.
        $this->assertSame(13, PaymentChannel::query()->where('gateway', 'belibayar')->count());
        $this->assertSame(1, PaymentChannel::query()->where('gateway', 'belibayar')->where('code', 'BCA')->count());
    }

    public function test_disabled_or_unknown_gateway_keeps_belibayar_catalog_and_binding(): void
    {
        $this->seedFixtureProduct();
        $this->seed(PaymentChannelSeeder::class);

        PaymentChannel::query()->where('gateway', 'belibayar')->where('code', 'QRIS')->update(['is_active' => true]);
        PaymentChannel::query()->where('gateway', 'tripay')->where('code', 'BCA')->update(['is_active' => true]);

        config()->set('payment.default', 'tripay');
        config()->set('payment.tripay.enabled', false);

        $this->assertInstanceOf(BelibayarService::class, app(PaymentGatewayInterface::class));
        $this->assertSame(['QRIS'], $this->renderedChannelCodes());

        config()->set('payment.default', 'midtrans');

        $this->assertInstanceOf(BelibayarService::class, app(PaymentGatewayInterface::class));
        $this->assertSame(['QRIS'], $this->renderedChannelCodes());
    }

    public function test_enabled_tripay_lists_only_active_tripay_channels(): void
    {
        $this->seedFixtureProduct();
        $this->seed(PaymentChannelSeeder::class);

        PaymentChannel::query()->where('gateway', 'belibayar')->update(['is_active' => true]);
        PaymentChannel::query()->where('gateway', 'tripay')->whereIn('code', ['QRIS', 'BCA'])->update(['is_active' => true]);

        config()->set('payment.default', 'tripay');
        config()->set('payment.tripay.enabled', true);

        $this->assertInstanceOf(TripayService::class, app(PaymentGatewayInterface::class));
        $this->assertSame(['BCA', 'QRIS'], $this->renderedChannelCodes());
    }
}