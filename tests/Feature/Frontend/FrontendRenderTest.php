<?php

namespace Tests\Feature\Frontend;

use App\Models\Banner;
use App\Models\Category;
use App\Models\PaymentChannel;
use App\Models\Product;
use App\Models\ProductItem;
use App\Models\Transaction;
use App\Services\Payment\PaymentGatewayInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FrontendRenderTest extends TestCase
{
    use RefreshDatabase;

    protected function seedFrontendFixture(): array
    {
        $category = Category::create([
            'name' => 'Games',
            'slug' => 'games',
            'icon' => '🎮',
            'is_active' => true,
        ]);

        $product = Product::create([
            'category_id' => $category->id,
            'name' => 'Mobile Legends',
            'slug' => 'mobile-legends',
            'is_popular' => true,
            'is_active' => true,
            'input_fields' => [
                ['name' => 'user_id', 'label' => 'User ID', 'type' => 'text', 'required' => true, 'placeholder' => '12345678'],
                ['name' => 'zone_id', 'label' => 'Zone ID', 'type' => 'text', 'required' => true, 'placeholder' => '1234'],
            ],
        ]);

        $item = ProductItem::create([
            'product_id' => $product->id,
            'name' => '86 Diamonds',
            'supplier_item_code' => 'ml-86',
            'buy_price' => '13000',
            'sell_price' => '15000',
            'flash_sale_price' => '12000',
            'flash_sale_start' => now()->subHour(),
            'flash_sale_end' => now()->addHour(),
            'is_active' => true,
        ]);

        $banner = Banner::create([
            'title' => 'Promo Merdeka',
            'image' => 'https://example.com/banner.jpg',
            'type' => 'carousel',
            'is_active' => true,
        ]);

        $channel = PaymentChannel::create([
            'gateway' => 'belibayar',
            'code' => 'QRIS',
            'name' => 'QRIS',
            'group' => 'QRIS',
            'flat_fee' => '0',
            'percent_fee' => '0.70',
            'is_active' => true,
        ]);

        return [$category, $product, $item, $banner, $channel];
    }

    public function test_homepage_renders_all_sections(): void
    {
        [$category, $product, $item, $banner] = $this->seedFrontendFixture();

        $response = $this->get('/');

        $response->assertStatus(200);
        $response->assertSee('Mobile Legends');
        $response->assertSee('Flash Sale');
        $response->assertSee('Games');
        $response->assertSee('Mengapa');
    }

    public function test_category_page_renders_products(): void
    {
        [$category, $product] = $this->seedFrontendFixture();

        $response = $this->get('/kategori/games');

        $response->assertStatus(200);
        $response->assertSee('Mobile Legends');
    }

    public function test_product_page_renders_checkout_steps_and_payment_groups(): void
    {
        [$category, $product] = $this->seedFrontendFixture();

        $response = $this->get('/games/mobile-legends');

        $response->assertStatus(200);
        $response->assertSee('Masukkan Data');
        $response->assertSee('Pilih Nominal');
        $response->assertSee('Pilih Pembayaran');
        $response->assertSee('Kontak');
        $response->assertSee('BELI SEKARANG');
        $response->assertSee('QRIS');
    }

    public function test_order_submit_redirects_to_invoice(): void
    {
        [$category, $product, $item, $banner, $channel] = $this->seedFrontendFixture();

        $gateway = $this->mock(PaymentGatewayInterface::class);
        $gateway->shouldReceive('gatewayName')->andReturn('belibayar');
        $gateway->shouldReceive('createTransaction')->once()->andReturnUsing(function ($transaction) { return [
            'reference' => $transaction->invoice_id,
            'transaction_id' => 'byrtrx_render', 'payment_channel' => 'QRIS',
            'amount' => (int) $transaction->total, 'status' => 'pending',
            'qr_url' => 'https://link.belibayar.id/test.png', 'expired_at' => now()->addHour()->timestamp,
        ]; });
        $this->app->instance(PaymentGatewayInterface::class, $gateway);

        $response = $this->post('/order', [
            'product_item_id' => $item->id,
            'payment_channel' => 'QRIS',
            'customer_data' => [
                'user_id' => '12345678',
                'zone_id' => '1234',
            ],
            'customer_phone' => '081234567890',
            'customer_email' => 'buyer@example.com',
        ]);

        $transaction = Transaction::query()->first();

        $this->assertNotNull($transaction);
        $response->assertRedirect(route('invoice.show', $transaction->invoice_id));
        $this->assertSame('byrtrx_render', $transaction->payment_ref);
        $this->assertSame('12000.00', $transaction->amount); // flash sale price
    }

    public function test_order_validation_rejects_missing_required_input_field(): void
    {
        [$category, $product, $item, $banner, $channel] = $this->seedFrontendFixture();

        $response = $this->post('/order', [
            'product_item_id' => $item->id,
            'payment_channel' => 'QRIS',
            'customer_data' => [
                'user_id' => '12345678',
                // zone_id sengaja dikosongkan (required di input_fields)
            ],
            'customer_phone' => '081234567890',
        ]);

        $response->assertSessionHasErrors('customer_data.zone_id');
        $this->assertSame(0, Transaction::count());
    }

    public function test_invoice_page_renders_transaction_detail(): void
    {
        [$category, $product, $item, $banner, $channel] = $this->seedFrontendFixture();

        $transaction = Transaction::create([
            'invoice_id' => 'INV-20260908-RENDER',
            'product_item_id' => $item->id,
            'customer_data' => ['user_id' => '12345678', 'zone_id' => '1234'],
            'customer_phone' => '081234567890',
            'amount' => '12000',
            'fee' => '84',
            'total' => '12084',
            'profit' => '-1000',
            'payment_method' => 'QRIS',
            'payment_channel' => 'QRIS',
            'payment_gateway' => 'belibayar',
            'payment_url' => 'https://link.belibayar.id/test.png',
            'status' => 'pending',
        ]);

        $response = $this->get('/invoice/INV-20260908-RENDER');

        $response->assertStatus(200);
        $response->assertSee('INV-20260908-RENDER');
        $response->assertSee('Menunggu Pembayaran');
        $response->assertSee('Instruksi Pembayaran');
    }
}
