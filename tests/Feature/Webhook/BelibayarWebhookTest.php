<?php

namespace Tests\Feature\Webhook;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductItem;
use App\Models\Transaction;
use App\Services\Supplier\SupplierInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BelibayarWebhookTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('payment.belibayar.webhook_secret', 'webhook-test-secret');
        config()->set('payment.belibayar.merchant_id', 'BYR-TEST');
    }

    private function transaction(string $invoice = 'INV-BELI-1', string $ref = 'byrtrx_test1'): Transaction
    {
        $category = Category::firstOrCreate(['slug' => 'games'], ['name' => 'Games']);
        $product = Product::firstOrCreate(['slug' => 'test-game'], ['category_id' => $category->id, 'name' => 'Test Game', 'supplier_type' => 'digiflazz']);
        $item = ProductItem::firstOrCreate(['product_id' => $product->id, 'name' => 'Voucher'], ['buy_price' => '12000', 'sell_price' => '15000']);
        return Transaction::create([
            'invoice_id' => $invoice, 'product_item_id' => $item->id,
            'customer_data' => ['user_id' => '123'], 'customer_phone' => '081234567890',
            'amount' => '15000', 'fee' => '105', 'total' => '15105', 'profit' => '3000',
            'payment_gateway' => 'belibayar', 'payment_channel' => 'QRIS',
            'payment_ref' => $ref, 'status' => 'pending',
        ]);
    }

    private function notify(array $payload, ?string $signature = null)
    {
        $raw = json_encode($payload, JSON_UNESCAPED_SLASHES);
        return $this->call('POST', '/webhook/belibayar', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_BELIBAYAR_SIGNATURE' => $signature ?? hash_hmac('sha256', $raw, 'webhook-test-secret'),
        ], $raw);
    }

    private function payload(string $status, string $invoice = 'INV-BELI-1', string $ref = 'byrtrx_test1'): array
    {
        return ['reference' => $invoice, 'transaction_id' => $ref, 'merchant_id' => 'BYR-TEST', 'method_api' => 'direct', 'payment_channel' => 'QRIS', 'amount' => 15105, 'status' => $status];
    }

    public function test_rejects_invalid_signature_and_mismatched_amount(): void
    {
        $transaction = $this->transaction();
        $this->notify($this->payload('paid'), 'wrong')->assertStatus(403);
        $this->notify([...$this->payload('paid'), 'amount' => 10])->assertStatus(422);
        $this->assertSame('pending', $transaction->fresh()->status);
    }

    public function test_accepts_signed_paid_webhook_when_merchant_id_differs_or_is_omitted(): void
    {
        $supplier = $this->mock(SupplierInterface::class);
        $supplier->shouldReceive('supplierName')->andReturn('digiflazz');
        $supplier->shouldReceive('topup')->twice()->andReturn(['supplier_ref' => 'DF-1', 'supplier_sn' => 'SN-1', 'status' => 'completed', 'raw' => []]);
        $this->app->instance(SupplierInterface::class, $supplier);

        $omitted = $this->transaction();
        $payload = $this->payload('paid');
        unset($payload['merchant_id']);
        $this->notify($payload)->assertOk();
        $this->assertSame('completed', $omitted->fresh()->status);

        $different = $this->transaction('INV-BELI-2', 'byrtrx_test2');
        $this->notify([...$this->payload('paid', 'INV-BELI-2', 'byrtrx_test2'), 'merchant_id' => 'BYR-OTHER'])->assertOk();
        $this->assertSame('completed', $different->fresh()->status);
    }

    public function test_rejects_mismatched_amount_channel_transaction_and_unknown_reference(): void
    {
        $transaction = $this->transaction();
        $this->notify([...$this->payload('paid'), 'amount' => 10])->assertStatus(422);
        $this->notify([...$this->payload('paid'), 'payment_channel' => 'OVO'])->assertStatus(422);
        $this->notify([...$this->payload('paid'), 'transaction_id' => 'byrtrx_other'])->assertStatus(422);
        $this->notify([...$this->payload('paid'), 'reference' => 'INV-UNKNOWN'])->assertStatus(404);
        $this->assertSame('pending', $transaction->fresh()->status);
    }

    public function test_duplicate_paid_webhook_fulfills_once(): void
    {
        $transaction = $this->transaction();
        $supplier = $this->mock(SupplierInterface::class);
        $supplier->shouldReceive('supplierName')->andReturn('digiflazz');
        $supplier->shouldReceive('topup')->once()->andReturn(['supplier_ref' => 'DF-1', 'supplier_sn' => 'SN-1', 'status' => 'completed', 'raw' => []]);
        $this->app->instance(SupplierInterface::class, $supplier);
        $this->notify($this->payload('paid'))->assertOk();
        $this->notify($this->payload('paid'))->assertOk();
        $this->assertSame('completed', $transaction->fresh()->status);
    }

    public function test_expired_then_late_paid_fulfills_only_once(): void
    {
        $transaction = $this->transaction();
        $supplier = $this->mock(SupplierInterface::class);
        $supplier->shouldReceive('supplierName')->andReturn('digiflazz');
        $supplier->shouldReceive('topup')->once()->andReturn(['supplier_ref' => 'DF-1', 'supplier_sn' => 'SN-1', 'status' => 'completed', 'raw' => []]);
        $this->app->instance(SupplierInterface::class, $supplier);
        $this->notify($this->payload('expired'))->assertOk();
        $this->assertSame('expired', $transaction->fresh()->status);
        $this->notify($this->payload('paid'))->assertOk();
        $this->notify($this->payload('paid'))->assertOk();
        $this->assertSame('completed', $transaction->fresh()->status);
    }

    public function test_rejects_a_different_payment_reference_and_ignores_withdrawal_event(): void
    {
        $transaction = $this->transaction();
        $this->notify([...$this->payload('paid'), 'transaction_id' => 'byrtrx_other'])->assertStatus(422);
        $this->notify(['event' => 'withdrawal.failed', 'reference' => 'WD-1', 'status' => 'rejected'])->assertOk();
        $this->assertSame('pending', $transaction->fresh()->status);
    }
}
