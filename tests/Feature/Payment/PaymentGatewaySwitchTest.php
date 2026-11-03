<?php

namespace Tests\Feature\Payment;

use App\Models\Category;
use App\Models\PaymentChannel;
use App\Models\Product;
use App\Models\ProductItem;
use App\Services\Payment\BelibayarService;
use App\Services\Payment\PaymentGatewayInterface;
use App\Services\Payment\TripayService;
use App\Support\PaymentGateway;
use Database\Seeders\PaymentChannelSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Perpindahan gateway harus fail-closed dan konsisten dengan katalog publik:
 * binding container dan daftar channel yang dilihat pembeli tidak boleh
 * berbeda pendapat. Tripay hanya efektif bila 'payment.default' eksplisit
 * tripay DAN 'tripay.enabled' true; selain itu Belibayar yang melayani.
 */
class PaymentGatewaySwitchTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    private function seedFixtureProduct(): void
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
    }

    /**
     * Kode channel yang benar-benar dirender ke pembeli pada halaman produk.
     *
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

    /**
     * @return array<string, array{0: string, 1: bool, 2: string}>
     */
    public static function gatewayMatrix(): array
    {
        return [
            'default literal belibayar' => ['belibayar', true, 'belibayar'],
            'tripay dipilih tapi belum diaktifkan' => ['tripay', false, 'belibayar'],
            'gateway tidak dikenal' => ['midtrans', true, 'belibayar'],
            'default kosong' => ['', true, 'belibayar'],
            'tripay lengkap' => ['tripay', true, 'tripay'],
            'tripay huruf besar dan spasi' => [' TRIPAY ', true, 'tripay'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('gatewayMatrix')]
    public function test_effective_gateway_is_fail_closed(string $default, bool $enabled, string $expected): void
    {
        config()->set('payment.default', $default);
        config()->set('payment.tripay.enabled', $enabled);

        $this->assertSame($expected, PaymentGateway::effective());
        $this->assertSame($expected === 'tripay', PaymentGateway::isTripay());
        $this->assertSame($expected === 'belibayar', PaymentGateway::isBelibayar());
    }

    public function test_enabled_flag_alone_never_switches_gateway(): void
    {
        config()->set('payment.default', 'belibayar');
        config()->set('payment.tripay.enabled', true);

        $this->assertSame('belibayar', PaymentGateway::effective());
        $this->assertInstanceOf(BelibayarService::class, app(PaymentGatewayInterface::class));
    }

    public function test_binding_and_public_catalog_agree_when_gateway_is_belibayar(): void
    {
        $this->seedFixtureProduct();
        $this->seed(PaymentChannelSeeder::class);

        PaymentChannel::query()->where('gateway', 'belibayar')->where('code', 'QRIS')->update(['is_active' => true]);
        PaymentChannel::query()->where('gateway', 'tripay')->whereIn('code', ['BCA', 'QRIS'])->update(['is_active' => true]);

        config()->set('payment.default', 'tripay');
        config()->set('payment.tripay.enabled', false);

        // Tripay punya channel aktif, tetapi gateway-nya fail-closed ke Belibayar:
        // katalog publik tidak boleh menampilkan satu pun channel Tripay.
        $this->assertInstanceOf(BelibayarService::class, app(PaymentGatewayInterface::class));
        $this->assertSame(['QRIS'], $this->renderedChannelCodes());
    }

    public function test_binding_and_public_catalog_agree_when_gateway_is_tripay(): void
    {
        $this->seedFixtureProduct();
        $this->seed(PaymentChannelSeeder::class);

        PaymentChannel::query()->where('gateway', 'belibayar')->update(['is_active' => true]);
        PaymentChannel::query()->where('gateway', 'tripay')->whereIn('code', ['BCA', 'QRIS'])->update(['is_active' => true]);
        // Channel Tripay yang tidak diaktifkan admin tidak boleh ikut tampil.
        PaymentChannel::query()->where('gateway', 'tripay')->where('code', 'BNI')->update(['is_active' => false]);

        config()->set('payment.default', 'tripay');
        config()->set('payment.tripay.enabled', true);

        $this->assertInstanceOf(TripayService::class, app(PaymentGatewayInterface::class));
        $this->assertSame(['BCA', 'QRIS'], $this->renderedChannelCodes());
    }

    public function test_unknown_gateway_falls_back_and_hides_tripay_channels(): void
    {
        $this->seedFixtureProduct();
        $this->seed(PaymentChannelSeeder::class);

        PaymentChannel::query()->where('gateway', 'belibayar')->where('code', 'DANA')->update(['is_active' => true]);
        PaymentChannel::query()->where('gateway', 'tripay')->where('code', 'DANA')->update(['is_active' => true]);

        config()->set('payment.default', 'midtrans');
        config()->set('payment.tripay.enabled', true);

        $this->assertSame('belibayar', PaymentGateway::effective());
        $this->assertInstanceOf(BelibayarService::class, app(PaymentGatewayInterface::class));
        $this->assertSame(['DANA'], $this->renderedChannelCodes());
    }

    public function test_tripay_callback_url_points_to_captshop_id(): void
    {
        $callback = (string) config('payment.tripay.callback_url');

        $this->assertSame('https://captshop.id/webhook/tripay', $callback);
        $this->assertSame('captshop.id', parse_url($callback, PHP_URL_HOST));
        $this->assertStringNotContainsString('next.captshop.id', $callback);
    }

    public function test_tripay_channel_map_keys_stay_internal_codes(): void
    {
        $map = config('payment.tripay.channels');

        $this->assertIsArray($map);
        $this->assertNotEmpty($map);

        foreach ($map as $internal => $tripayCode) {
            $this->assertSame(strtoupper($internal), $internal, 'Kunci peta harus kode internal huruf besar.');
            $this->assertIsString($tripayCode);
            $this->assertNotSame('', trim($tripayCode), "Kode Tripay untuk {$internal} tidak boleh kosong.");
        }
    }
}