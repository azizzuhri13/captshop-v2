<?php

namespace Tests\Feature\Order;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductItem;
use App\Models\Transaction;
use App\Services\Payment\BelibayarClient;
use App\Services\Supplier\SupplierInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class BelibayarInvoiceStatusTest extends TestCase
{
    use RefreshDatabase;

    private function pending(): Transaction
    {
        $category = Category::create(['name' => 'Games', 'slug' => 'games']);
        $product = Product::create(['category_id' => $category->id, 'name' => 'Game', 'slug' => 'game', 'supplier_type' => 'digiflazz']);
        $item = ProductItem::create(['product_id' => $product->id, 'name' => 'Voucher', 'buy_price' => '10000', 'sell_price' => '15000']);

        return Transaction::create([
            'invoice_id' => 'INV-BY-TEST', 'product_item_id' => $item->id,
            'customer_data' => ['user_id' => '123'], 'customer_phone' => '081234567890',
            'amount' => '15000', 'fee' => '0', 'total' => '15000', 'profit' => '5000',
            'payment_gateway' => 'belibayar', 'payment_channel' => 'QRIS',
            'payment_ref' => 'byrtrx_invoice', 'status' => 'pending',
        ]);
    }

    public function test_matching_paid_inquiry_fulfills_once_even_when_order_expired(): void
    {
        $transaction = $this->pending();
        $transaction->forceFill(['status' => 'expired'])->save();
        $client = Mockery::mock(BelibayarClient::class);
        $client->shouldReceive('status')->once()->with($transaction->invoice_id)->andReturn([
            'reference' => $transaction->invoice_id, 'transaction_id' => $transaction->payment_ref,
            'amount' => 15000, 'payment_channel' => 'QRIS', 'status' => 'paid',
        ]);
        $this->app->instance(BelibayarClient::class, $client);
        $supplier = $this->mock(SupplierInterface::class);
        $supplier->shouldReceive('topup')->once()->andReturn(['supplier_ref' => 'DF-1', 'status' => 'completed', 'raw' => []]);
        $this->app->instance(SupplierInterface::class, $supplier);

        $this->post(route('invoice.check-status', $transaction->invoice_id))->assertRedirect();
        $this->post(route('invoice.check-status', $transaction->invoice_id))->assertRedirect();
        $this->assertSame('completed', $transaction->fresh()->status);
    }

    public function test_mismatched_inquiry_cannot_mark_order_paid(): void
    {
        $transaction = $this->pending();
        $client = Mockery::mock(BelibayarClient::class);
        $client->shouldReceive('status')->once()->andReturn([
            'reference' => $transaction->invoice_id, 'transaction_id' => 'byrtrx_other',
            'amount' => 15000, 'payment_channel' => 'QRIS', 'status' => 'paid',
        ]);
        $this->app->instance(BelibayarClient::class, $client);
        $this->post(route('invoice.check-status', $transaction->invoice_id))->assertRedirect();
        $this->assertSame('pending', $transaction->fresh()->status);
    }
}
