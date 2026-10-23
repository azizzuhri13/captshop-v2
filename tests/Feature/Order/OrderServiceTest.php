<?php

namespace Tests\Feature\Order;

use App\Models\Category;
use App\Models\PaymentChannel;
use App\Models\Product;
use App\Models\ProductItem;
use App\Services\OrderService;
use App\Services\Payment\PaymentException;
use App\Services\Payment\PaymentGatewayInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Tests\TestCase;

class OrderServiceTest extends TestCase
{
    use RefreshDatabase, WithFaker;

    /** Checkout DigiFlazz butuh pricelist segar; fixture ini yang menyediakannya. */
    private function fakeFreshCatalog(string $sku): void
    {
        $path = storage_path('framework/testing/digiflazz-'.uniqid().'.json');
        config(['supplier.digiflazz.pricelist.cache_path' => $path]);
        @mkdir(dirname($path), 0775, true);
        file_put_contents($path, json_encode([
            'version' => 1,
            'fetched_at' => now()->toIso8601String(),
            'count' => 1,
            'items' => [[
                'buyer_sku_code' => $sku,
                'price' => 13000,
                'buyer_product_status' => true,
                'seller_product_status' => true,
                'seller_name' => 'GAMEPEDIA',
            ]],
        ]));
    }

    protected function seedOrderFixture(): array
    {
        $channel = PaymentChannel::create([
            'gateway' => 'belibayar',
            'code' => 'QRIS',
            'name' => 'QRIS',
            'group' => 'QRIS',
            'flat_fee' => '0',
            'percent_fee' => '0.70',
            'is_active' => true,
            'sort_order' => 30,
        ]);

        $category = Category::create([
            'name' => 'Games',
            'slug' => 'games',
        ]);

        $product = Product::create([
            'category_id' => $category->id,
            'name' => 'Mobile Legends',
            'slug' => 'mobile-legends',
            'supplier_type' => 'digiflazz',
            'supplier_code' => 'mobile-legend',
        ]);

        $item = ProductItem::create([
            'product_id' => $product->id,
            'name' => '86 Diamonds',
            'supplier_item_code' => 'ml-86',
            'buy_price' => '13000',
            'sell_price' => '15000',
            'is_active' => true,
        ]);

        $this->fakeFreshCatalog('ml-86');

        return [$channel, $product, $item];
    }

    public function test_place_creates_pending_transaction_with_correct_amounts(): void
    {
        [$channel, $product, $item] = $this->seedOrderFixture();

        $gateway = $this->mock(PaymentGatewayInterface::class);
        $gateway->shouldReceive('gatewayName')->andReturn('belibayar');
        $gateway->shouldReceive('createTransaction')->once()->andReturnUsing(function ($transaction) {
            return ['reference' => $transaction->invoice_id, 'transaction_id' => 'byrtrx_order', 'payment_channel' => 'QRIS', 'amount' => (int) $transaction->total, 'status' => 'pending', 'qr_url' => 'https://link.belibayar.id/test.png', 'expired_at' => now()->addHour()->timestamp];
        });
        $this->app->instance(PaymentGatewayInterface::class, $gateway);

        $service = $this->app->make(OrderService::class);

        $transaction = $service->place([
            'product_item_id' => $item->id,
            'payment_channel' => 'QRIS',
            'customer_data' => ['user_id' => '12345', 'zone_id' => '6789'],
            'customer_phone' => '081234567890',
            'customer_email' => 'buyer@example.com',
        ]);

        $this->assertSame('pending', $transaction->status);
        $this->assertSame('15000.00', $transaction->amount);
        $this->assertSame('105.00', $transaction->fee);
        $this->assertSame('15105.00', $transaction->total);
        $this->assertSame('2000.00', $transaction->profit);
        $this->assertSame('byrtrx_order', $transaction->payment_ref);
        $this->assertNull($transaction->payment_url);
        $this->assertNotNull($transaction->expired_at);
    }

    public function test_place_throws_when_item_inactive(): void
    {
        [$channel, $product, $item] = $this->seedOrderFixture();

        $item->update(['is_active' => false]);

        $service = $this->app->make(OrderService::class);

        $this->expectException(\InvalidArgumentException::class);

        $service->place([
            'product_item_id' => $item->id,
            'payment_channel' => 'QRIS',
            'customer_data' => ['user_id' => '12345'],
            'customer_phone' => '081234567890',
        ]);
    }

    public function test_place_rolls_back_when_gateway_fails(): void
    {
        [$channel, $product, $item] = $this->seedOrderFixture();

        $gateway = $this->mock(PaymentGatewayInterface::class);
        $gateway->shouldReceive('gatewayName')->andReturn('belibayar');
        $gateway->shouldReceive('createTransaction')->once()->andThrow(
            new PaymentException('gateway down')
        );
        $this->app->instance(PaymentGatewayInterface::class, $gateway);

        $service = $this->app->make(OrderService::class);

        $this->expectException(PaymentException::class);
        $service->place([
            'product_item_id' => $item->id,
            'paid_channel' => null,
            'payment_channel' => 'QRIS',
            'customer_data' => ['user_id' => '12345'],
            'customer_phone' => '081234567890',
        ]);

    }
}
