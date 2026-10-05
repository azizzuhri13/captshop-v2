<?php

namespace Tests\Feature\Webhook;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductItem;
use App\Models\Transaction;
use App\Services\Supplier\SupplierInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class TripayWebhookTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('payment.tripay.private_key', 'test-private-key');
    }

    protected function createPendingTransaction(): Transaction
    {
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
        ]);

        return Transaction::create([
            'invoice_id' => 'INV-20260908-TEST01',
            'product_item_id' => $item->id,
            'customer_data' => ['user_id' => '12345', 'zone_id' => '6789'],
            'customer_phone' => '081234567890',
            'amount' => '15000',
            'fee' => '105',
            'total' => '15105',
            'profit' => '2000',
            'payment_method' => 'QRIS',
            'payment_channel' => 'QRIS',
            'status' => 'pending',
        ]);
    }

    public function test_rejects_invalid_signature(): void
    {
        $transaction = $this->createPendingTransaction();

        $response = $this->postJson('/webhook/tripay', [
            'merchant_ref' => $transaction->invoice_id,
            'status' => 'PAID',
            'amount' => 15105,
        ], ['X-Callback-Signature' => 'invalid-signature']);

        $response->assertStatus(403);

        $this->assertSame('pending', $transaction->fresh()->status);
    }

    public function test_paid_webhook_updates_status_and_triggers_fulfillment(): void
    {
        $transaction = $this->createPendingTransaction();

        // Mock supplier: top-up langsung sukses.
        $supplier = $this->mock(SupplierInterface::class);
        $supplier->shouldReceive('topup')->once()->andReturn([
            'supplier_ref' => 'DF-REF-1',
            'supplier_sn' => 'SN-987654321',
            'status' => 'completed',
            'message' => 'Sukses',
            'raw' => ['data' => ['status' => 'Sukses']],
        ]);
        $this->app->instance(SupplierInterface::class, $supplier);

        $payload = [
            'merchant_ref' => $transaction->invoice_id,
            'status' => 'PAID',
            'amount' => 15105,
        ];

        $signature = hash_hmac('sha256', $transaction->invoice_id.'PAID15105', 'test-private-key');

        $response = $this->postJson('/webhook/tripay', $payload, [
            'X-Callback-Signature' => $signature,
        ]);

        $response->assertStatus(200)->assertJson(['success' => true]);

        $transaction = $transaction->refresh();

        $this->assertSame('completed', $transaction->status);
        $this->assertNotNull($transaction->paid_at);
        $this->assertNotNull($transaction->processed_at);
        $this->assertNotNull($transaction->completed_at);
        $this->assertSame('DF-REF-1', $transaction->supplier_ref);
        $this->assertSame('SN-987654321', $transaction->supplier_sn);
    }

    public function test_expired_webhook_marks_pending_transaction_expired(): void
    {
        $transaction = $this->createPendingTransaction();

        $signature = hash_hmac('sha256', $transaction->invoice_id.'EXPIRED15105', 'test-private-key');

        $response = $this->postJson('/webhook/tripay', [
            'merchant_ref' => $transaction->invoice_id,
            'status' => 'EXPIRED',
            'amount' => 15105,
        ], ['X-Callback-Signature' => $signature]);

        $response->assertStatus(200);

        $this->assertSame('expired', $transaction->fresh()->status);
    }

    public function test_unknown_invoice_returns_404(): void
    {
        $signature = hash_hmac('sha256', 'INV-UNKNOWNPAID15000', 'test-private-key');

        $response = $this->postJson('/webhook/tripay', [
            'merchant_ref' => 'INV-UNKNOWN',
            'status' => 'PAID',
            'amount' => 15000,
        ], ['X-Callback-Signature' => $signature]);

        $response->assertStatus(404);
    }
}
