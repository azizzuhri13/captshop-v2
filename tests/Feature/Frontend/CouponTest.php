<?php

namespace Tests\Feature\Frontend;

use App\Models\Category;
use App\Models\Coupon;
use App\Models\PaymentChannel;
use App\Models\Product;
use App\Models\ProductItem;
use App\Models\Transaction;
use App\Services\Payment\PaymentGatewayInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CouponTest extends TestCase
{
    use RefreshDatabase;

    protected function seedFixture(): array
    {
        $category = Category::create(['name' => 'Games', 'slug' => 'games', 'is_active' => true]);

        $product = Product::create([
            'category_id' => $category->id,
            'name' => 'Mobile Legends',
            'slug' => 'mobile-legends',
            'is_active' => true,
            'input_fields' => [
                ['name' => 'user_id', 'label' => 'User ID', 'type' => 'text', 'required' => true],
            ],
        ]);

        $item = ProductItem::create([
            'product_id' => $product->id,
            'name' => '86 Diamonds',
            'buy_price' => '13000',
            'sell_price' => '15000',
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

        return [$category, $product, $item, $channel];
    }

    protected function mockGateway(): void
    {
        $gateway = $this->mock(PaymentGatewayInterface::class);
        $gateway->shouldReceive('gatewayName')->andReturn('belibayar');
        $gateway->shouldReceive('createTransaction')->andReturnUsing(fn ($transaction) => [
            'reference' => $transaction->invoice_id, 'transaction_id' => 'byrtrx_coupon',
            'payment_channel' => 'QRIS', 'amount' => (int) $transaction->total,
            'status' => 'pending', 'qr_url' => 'https://link.belibayar.id/test.png',
            'expired_at' => now()->addHour()->timestamp,
        ]);
        $this->app->instance(PaymentGatewayInterface::class, $gateway);
    }

    public function test_check_returns_valid_coupon_with_discount(): void
    {
        $this->seedFixture();

        Coupon::create([
            'code' => 'HEMAT10',
            'discount_type' => 'percentage',
            'discount_value' => '10',
            'min_purchase' => '10000',
            'is_active' => true,
        ]);

        $response = $this->postJson('/coupon/check', ['code' => 'HEMAT10', 'amount' => 15000]);

        $response->assertOk()->assertJsonPath('valid', true);

        $this->assertSame(1500.0, (float) $response->json('discount'));
        $this->assertSame('HEMAT10', $response->json('code'));
    }

    public function test_check_rejects_inactive_coupon(): void
    {
        $this->seedFixture();

        Coupon::create([
            'code' => 'MATI',
            'discount_type' => 'nominal',
            'discount_value' => '5000',
            'is_active' => false,
        ]);

        $response = $this->postJson('/coupon/check', ['code' => 'MATI', 'amount' => 15000]);

        $response->assertStatus(422)->assertJsonPath('valid', false);
    }

    public function test_check_rejects_exhausted_quota(): void
    {
        $this->seedFixture();

        Coupon::create([
            'code' => 'HABIS',
            'discount_type' => 'nominal',
            'discount_value' => '1000',
            'max_usage' => 2,
            'used_count' => 2,
            'is_active' => true,
        ]);

        $response = $this->postJson('/coupon/check', ['code' => 'HABIS', 'amount' => 15000]);

        $response->assertStatus(422)->assertJsonPath('valid', false);
    }

    public function test_check_rejects_below_min_purchase(): void
    {
        $this->seedFixture();

        Coupon::create([
            'code' => 'BESAR',
            'discount_type' => 'nominal',
            'discount_value' => '2000',
            'min_purchase' => '50000',
            'is_active' => true,
        ]);

        $response = $this->postJson('/coupon/check', ['code' => 'BESAR', 'amount' => 15000]);

        $response->assertStatus(422)->assertJsonPath('valid', false);
    }

    public function test_check_rejects_unknown_code(): void
    {
        $this->postJson('/coupon/check', ['code' => 'TIDAKADA', 'amount' => 15000])
            ->assertStatus(422)
            ->assertJsonPath('valid', false);
    }

    public function test_order_with_valid_coupon_applies_discount_and_increments_usage(): void
    {
        [$category, $product, $item, $channel] = $this->seedFixture();
        $this->mockGateway();

        $coupon = Coupon::create([
            'code' => 'HEMAT10',
            'discount_type' => 'percentage',
            'discount_value' => '10',
            'min_purchase' => '10000',
            'max_usage' => 5,
            'is_active' => true,
        ]);

        $response = $this->post('/order', [
            'product_item_id' => $item->id,
            'payment_channel' => 'QRIS',
            'customer_data' => ['user_id' => '12345678'],
            'customer_phone' => '081234567890',
            'customer_email' => 'buyer@example.com',
            'coupon_code' => 'hemat10',
        ]);

        $transaction = Transaction::query()->first();

        $this->assertNotNull($transaction);
        $response->assertRedirect(route('invoice.show', $transaction->invoice_id));

        // Biaya dibulatkan ke IDR bulat agar total sama dengan nominal Belibayar.
        $this->assertSame('15000.00', $transaction->amount);
        $this->assertSame('HEMAT10', $transaction->coupon_code);
        $this->assertSame('1500.00', $transaction->coupon_discount);
        $this->assertSame('95.00', $transaction->fee);
        $this->assertSame('13595.00', $transaction->total);
        $this->assertSame(1, $coupon->fresh()->used_count);
    }

    public function test_order_with_expired_coupon_is_rejected(): void
    {
        [$category, $product, $item, $channel] = $this->seedFixture();
        $this->mockGateway();

        Coupon::create([
            'code' => 'LAMA',
            'discount_type' => 'nominal',
            'discount_value' => '1000',
            'end_at' => now()->subDay(),
            'is_active' => true,
        ]);

        $response = $this->post('/order', [
            'product_item_id' => $item->id,
            'payment_channel' => 'QRIS',
            'customer_data' => ['user_id' => '12345678'],
            'customer_phone' => '081234567890',
            'customer_email' => 'buyer@example.com',
            'coupon_code' => 'LAMA',
        ]);

        $response->assertSessionHasErrors('order');
        $this->assertSame(0, Transaction::count());
    }

    public function test_order_without_coupon_unchanged(): void
    {
        [$category, $product, $item, $channel] = $this->seedFixture();
        $this->mockGateway();

        $response = $this->post('/order', [
            'product_item_id' => $item->id,
            'payment_channel' => 'QRIS',
            'customer_data' => ['user_id' => '12345678'],
            'customer_phone' => '081234567890',
            'customer_email' => 'buyer@example.com',
        ]);

        $transaction = Transaction::query()->first();

        $this->assertNotNull($transaction);
        $response->assertRedirect(route('invoice.show', $transaction->invoice_id));
        $this->assertNull($transaction->coupon_code);
        $this->assertSame('0.00', $transaction->coupon_discount);
        $this->assertSame('15000.00', $transaction->amount);
        $this->assertSame('105.00', $transaction->fee); // 0.7% x 15000
        $this->assertSame('15105.00', $transaction->total);
    }
}
