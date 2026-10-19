<?php

namespace Tests\Feature\Webhook;

use App\Models\Category;
use App\Models\PaymentChannel;
use App\Models\Product;
use App\Models\ProductItem;
use App\Models\Transaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DigiflazzWebhookTest extends TestCase
{
    use RefreshDatabase;

    protected function createPendingTransaction(string $invoiceId = 'INV-TEST-DF01'): Transaction
    {
        $category = Category::create(['name' => 'Games', 'slug' => 'games', 'is_active' => true]);
        $product = Product::create([
            'category_id' => $category->id,
            'name' => 'Mobile Legends',
            'slug' => 'mobile-legends',
            'is_active' => true,
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

        return Transaction::create([
            'invoice_id' => $invoiceId,
            'product_item_id' => $item->id,
            'payment_channel' => $channel->code,
            'payment_method' => $channel->group,
            'payment_gateway' => 'belibayar',
            'amount' => 15000,
            'fee' => 105,
            'total' => 15105,
            'status' => 'processing',
            'customer_phone' => '081234567890',
            'customer_email' => 'buyer@test.com',
            'customer_data' => ['user_id' => '123456'],
        ]);
    }

    public function test_ping_event_returns_ok(): void
    {
        $response = $this->postJson('/webhook/digiflazz', [], [
            'X-Digiflazz-Event' => 'ping',
        ]);

        $response->assertOk()
            ->assertJson([
                'status' => 'ok',
                'message' => 'pong',
            ]);
    }

    public function test_invalid_signature_is_rejected(): void
    {
        config(['supplier.digiflazz.webhook_secret' => 'valid-secret']);

        $payload = ['data' => ['ref_id' => 'INV-TEST-DF01', 'status' => 'Sukses']];

        $response = $this->postJson('/webhook/digiflazz', $payload, [
            'X-Hub-Signature' => 'sha1=invalidsignature',
        ]);

        $response->assertStatus(403)
            ->assertJson(['status' => 'unauthorized']);
    }

    public function test_valid_webhook_marks_transaction_completed_with_sn(): void
    {
        $trx = $this->createPendingTransaction('INV-DF-SUKSES');
        $secret = 'test-secret';
        config(['supplier.digiflazz.webhook_secret' => $secret]);

        $body = [
            'data' => [
                'ref_id' => 'INV-DF-SUKSES',
                'status' => 'Sukses',
                'sn' => 'SN123456789',
                'message' => 'Transaksi Sukses',
            ],
        ];

        $raw = json_encode($body);
        $sig = 'sha1='.hash_hmac('sha1', $raw, $secret);

        $response = $this->call(
            'POST',
            '/webhook/digiflazz',
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X_HUB_SIGNATURE' => $sig,
            ],
            $raw
        );

        $response->assertOk()
            ->assertJson(['status' => 'ok']);

        $this->assertDatabaseHas('transactions', [
            'invoice_id' => 'INV-DF-SUKSES',
            'status' => 'completed',
            'supplier_sn' => 'SN123456789',
        ]);
    }

    public function test_failed_webhook_marks_transaction_failed(): void
    {
        $trx = $this->createPendingTransaction('INV-DF-GAGAL');
        $secret = 'test-secret';
        config(['supplier.digiflazz.webhook_secret' => $secret]);

        $body = [
            'data' => [
                'ref_id' => 'INV-DF-GAGAL',
                'status' => 'Gagal',
                'message' => 'Nomor tujuan salah',
            ],
        ];

        $raw = json_encode($body);
        $sig = 'sha1='.hash_hmac('sha1', $raw, $secret);

        $response = $this->call(
            'POST',
            '/webhook/digiflazz',
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X_HUB_SIGNATURE' => $sig,
            ],
            $raw
        );

        $response->assertOk()
            ->assertJson(['status' => 'ok']);

        $this->assertDatabaseHas('transactions', [
            'invoice_id' => 'INV-DF-GAGAL',
            'status' => 'failed',
        ]);
    }

    public function test_unconfigured_secret_rejects_webhook(): void
    {
        config(['supplier.digiflazz.webhook_secret' => null]);

        $response = $this->call(
            'POST',
            '/webhook/digiflazz',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['data' => ['ref_id' => 'INV-TEST-DF01', 'status' => 'Sukses']])
        );

        $response->assertStatus(403);
    }

    public function test_unpaid_transaction_is_not_completed_by_webhook(): void
    {
        $trx = $this->createPendingTransaction('INV-DF-UNPAID');
        $trx->update(['status' => 'pending']); // Belum dibayar

        $secret = 'test-secret';
        config(['supplier.digiflazz.webhook_secret' => $secret]);

        $body = ['data' => ['ref_id' => 'INV-DF-UNPAID', 'status' => 'Sukses', 'sn' => 'SN999']];
        $raw = json_encode($body);
        $sig = 'sha1='.hash_hmac('sha1', $raw, $secret);

        $response = $this->call(
            'POST',
            '/webhook/digiflazz',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_X_HUB_SIGNATURE' => $sig],
            $raw
        );

        $response->assertOk();
        $this->assertEquals('pending', $trx->fresh()->status);
        $this->assertNull($trx->fresh()->supplier_sn);
    }

    public function test_manual_product_transaction_is_not_modified_by_digiflazz_webhook(): void
    {
        $trx = $this->createPendingTransaction('INV-DF-MANUAL');
        $trx->productItem->product->update(['supplier_type' => 'manual']);

        $secret = 'test-secret';
        config(['supplier.digiflazz.webhook_secret' => $secret]);

        $body = ['data' => ['ref_id' => 'INV-DF-MANUAL', 'status' => 'Sukses', 'sn' => 'SN-MANUAL']];
        $raw = json_encode($body);
        $sig = 'sha1='.hash_hmac('sha1', $raw, $secret);

        $response = $this->call(
            'POST',
            '/webhook/digiflazz',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_X_HUB_SIGNATURE' => $sig],
            $raw
        );

        $response->assertOk();
        $this->assertEquals('processing', $trx->fresh()->status);
        $this->assertNull($trx->fresh()->supplier_sn);
    }
}
