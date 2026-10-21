<?php

namespace Tests\Feature\Order;

use App\Models\CanvaAccount;
use App\Models\CapcutAccount;
use App\Models\Category;
use App\Models\NetflixAccount;
use App\Models\PaymentChannel;
use App\Models\Product;
use App\Models\ProductItem;
use App\Services\OrderService;
use App\Services\Payment\PaymentGatewayInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PremiumAccountStockTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $mockGateway = \Mockery::mock(PaymentGatewayInterface::class);
        $mockGateway->shouldReceive('gatewayName')->andReturn('belibayar');
        $this->app->instance(PaymentGatewayInterface::class, $mockGateway);
    }

    public function test_canva_pro_with_zero_stock_cannot_be_ordered(): void
    {
        $category = Category::create(['name' => 'Apps Premium', 'slug' => 'apps-premium']);
        $product = Product::create([
            'category_id' => $category->id,
            'name' => 'Canva Pro',
            'slug' => 'canva-pro',
            'supplier_type' => 'manual',
            'is_active' => true,
        ]);
        $item = ProductItem::create([
            'product_id' => $product->id,
            'name' => '1 Bulan',
            'buy_price' => 10000,
            'sell_price' => 15000,
            'is_active' => true,
        ]);

        PaymentChannel::create([
            'code' => 'QRIS',
            'name' => 'QRIS',
            'gateway' => 'belibayar',
            'group' => 'E-Wallet & QRIS',
            'is_active' => true,
        ]);

        $this->assertTrue($item->isOutOfStock());
        $this->assertEquals(0, $item->getStock());

        // Validasi form order harus menolak dengan 422
        $response = $this->post(route('order.store'), [
            'product_item_id' => $item->id,
            'payment_channel' => 'QRIS',
            'customer_phone' => '081234567890',
            'customer_email' => 'buyer@test.com',
            'customer_data' => ['email' => 'buyer@test.com'],
        ]);

        $response->assertSessionHasErrors(['product_item_id']);

        // OrderService::place juga harus throw exception bila dipanggil langsung
        $orderService = app(OrderService::class);
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('stok untuk paket 1 Bulan sedang habis');

        $orderService->place([
            'product_item_id' => $item->id,
            'payment_channel' => 'QRIS',
            'customer_phone' => '081234567890',
            'customer_email' => 'buyer@test.com',
            'customer_data' => ['email' => 'buyer@test.com'],
        ]);
    }

    public function test_netflix_with_ready_stock_can_be_ordered(): void
    {
        $category = Category::create(['name' => 'Apps Premium', 'slug' => 'apps-premium']);
        $product = Product::create([
            'category_id' => $category->id,
            'name' => 'Netflix Premium',
            'slug' => 'netflix-premium',
            'supplier_type' => 'manual',
            'is_active' => true,
        ]);
        $item = ProductItem::create([
            'product_id' => $product->id,
            'name' => '1P1U 1 Bulan',
            'buy_price' => 20000,
            'sell_price' => 30000,
            'is_active' => true,
        ]);

        NetflixAccount::create([
            'email' => 'ready@netflix.com',
            'password' => 'pass123',
            'status' => 'ready',
        ]);

        $this->assertFalse($item->isOutOfStock());
        $this->assertEquals(1, $item->getStock());
    }

    public function test_capcut_with_ready_stock_can_be_ordered(): void
    {
        $category = Category::create(['name' => 'Apps Premium', 'slug' => 'apps-premium']);
        $product = Product::create([
            'category_id' => $category->id,
            'name' => 'Capcut',
            'slug' => 'capcut',
            'supplier_type' => 'manual',
            'is_active' => true,
        ]);
        $item = ProductItem::create([
            'product_id' => $product->id,
            'name' => '1 bulan',
            'buy_price' => 20000,
            'sell_price' => 25000,
            'is_active' => true,
        ]);

        $this->assertTrue($item->isOutOfStock());
        $this->assertEquals(0, $item->getStock());

        // Tambah akun ready
        CapcutAccount::create([
            'email' => 'ready@capcut.com',
            'password' => 'secret123',
            'status' => 'ready',
        ]);

        $this->assertFalse($item->fresh()->isOutOfStock());
        $this->assertEquals(1, $item->fresh()->getStock());
    }

    public function test_non_premium_product_is_not_stock_tracked(): void
    {
        $category = Category::create(['name' => 'Top Up Games', 'slug' => 'topup']);
        $product = Product::create([
            'category_id' => $category->id,
            'name' => 'Mobile Legends',
            'slug' => 'mobile-legends',
            'supplier_type' => 'digiflazz',
            'is_active' => true,
        ]);
        $item = ProductItem::create([
            'product_id' => $product->id,
            'name' => '86 Diamonds',
            'buy_price' => 18000,
            'sell_price' => 20000,
            'is_active' => true,
        ]);

        $this->assertFalse($item->isStockTracked());
        $this->assertNull($item->getStock());
        $this->assertFalse($item->isOutOfStock());
    }
}
