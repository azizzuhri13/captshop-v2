<?php

namespace Tests\Feature\Order;

use App\Models\Category;
use App\Models\PaymentChannel;
use App\Models\Product;
use App\Models\ProductItem;
use App\Services\OrderService;
use App\Services\Payment\BelibayarClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class BelibayarOrderTest extends TestCase
{
    use RefreshDatabase;

    public function test_unpayable_gateway_response_rolls_back_order(): void
    {
        config()->set('payment.default', 'belibayar');
        PaymentChannel::create(['gateway' => 'belibayar', 'code' => 'QRIS', 'name' => 'QRIS', 'group' => 'QRIS', 'flat_fee' => '0', 'percent_fee' => '0', 'is_active' => true]);
        $category = Category::create(['name' => 'Games', 'slug' => 'games']);
        $product = Product::create(['category_id' => $category->id, 'name' => 'Voucher', 'slug' => 'voucher']);
        $item = ProductItem::create(['product_id' => $product->id, 'name' => 'Voucher', 'buy_price' => '10000', 'sell_price' => '15000', 'is_active' => true]);
        $client = Mockery::mock(BelibayarClient::class);
        $client->shouldReceive('charge')->once()->andReturnUsing(fn ($body) => [
            'reference' => $body['reference'], 'transaction_id' => 'byrtrx_missing_qr',
            'payment_channel' => 'QRIS', 'amount' => $body['amount'], 'status' => 'pending',
            'expired_at' => now()->addHour()->timestamp,
        ]);
        $this->app->instance(BelibayarClient::class, $client);

        $this->expectException(\App\Services\Payment\PaymentException::class);
        try {
            app(OrderService::class)->place(['product_item_id' => $item->id, 'payment_channel' => 'QRIS', 'customer_data' => ['user_id' => '123'], 'customer_phone' => '081234567890', 'customer_email' => 'buyer@example.com']);
        } finally {
            $this->assertSame(0, \App\Models\Transaction::count());
        }
    }

    public function test_historical_transaction_is_not_mislabeled_as_belibayar(): void
    {
        $category = Category::create(['name' => 'Games', 'slug' => 'games']);
        $product = Product::create(['category_id' => $category->id, 'name' => 'Voucher', 'slug' => 'voucher']);
        $item = ProductItem::create(['product_id' => $product->id, 'name' => 'Voucher', 'buy_price' => '10000', 'sell_price' => '15000']);
        $legacy = \App\Models\Transaction::create([
            'invoice_id' => 'INV-LEGACY-1', 'product_item_id' => $item->id,
            'customer_data' => ['user_id' => '123'], 'customer_phone' => '081234567890',
            'amount' => '15000', 'fee' => '0', 'total' => '15000', 'profit' => '5000',
            'payment_channel' => 'QRIS', 'status' => 'completed',
        ]);
        $this->assertNull($legacy->fresh()->payment_gateway);
    }

    public function test_seeding_disables_old_channels_without_deleting_history(): void
    {
        $old = PaymentChannel::create(['gateway' => 'legacy', 'code' => 'QRIS', 'name' => 'Old QRIS', 'group' => 'QRIS', 'flat_fee' => '0', 'percent_fee' => '0', 'is_active' => true]);
        $this->seed(\Database\Seeders\PaymentChannelSeeder::class);
        $this->assertFalse($old->fresh()->is_active);
        $this->assertTrue(PaymentChannel::query()->whereKey($old->id)->exists());
        $this->assertFalse(PaymentChannel::query()->where('gateway', 'belibayar')->where('code', 'QRIS')->firstOrFail()->is_active);
    }

    public function test_qris_order_records_instruction_and_gateway_expiry(): void
    {
        config()->set('payment.default', 'belibayar');
        PaymentChannel::create(['gateway'=>'belibayar','code'=>'QRIS','name'=>'QRIS','group'=>'QRIS','flat_fee'=>'0','percent_fee'=>'0','is_active'=>true]);
        $cat = Category::create(['name'=>'Games','slug'=>'games']);
        $product = Product::create(['category_id'=>$cat->id,'name'=>'Voucher','slug'=>'voucher','supplier_type'=>'digiflazz']);
        $item = ProductItem::create(['product_id'=>$product->id,'name'=>'10 gems','buy_price'=>'10000','sell_price'=>'15000','is_active'=>true]);
        $client = Mockery::mock(BelibayarClient::class);
        $client->shouldReceive('charge')->once()->andReturnUsing(function ($payload) {
            return ['reference'=>$payload['reference'],'transaction_id'=>'byrtrx_new','status'=>'pending','payment_channel'=>'QRIS','amount'=>$payload['amount'],'qr_content'=>'000201TEST','qr_url'=>'https://link.belibayar.id/test.png','expired_at'=>now()->addMinutes(35)->timestamp];
        });
        $this->app->instance(BelibayarClient::class, $client);
        $transaction = app(OrderService::class)->place(['product_item_id'=>$item->id,'payment_channel'=>'QRIS','customer_data'=>['user_id'=>'123'],'customer_phone'=>'081234567890','customer_email'=>'buyer@example.com']);
        $this->assertSame('belibayar', $transaction->payment_gateway);
        $this->assertSame('byrtrx_new', $transaction->payment_ref);
        $this->assertSame('000201TEST', $transaction->payment_instructions['qr_content']);
        $this->assertNotNull($transaction->expired_at);
    }
}
