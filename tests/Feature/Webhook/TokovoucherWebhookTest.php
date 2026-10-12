<?php

namespace Tests\Feature\Webhook;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductItem;
use App\Models\Transaction;
use App\Services\Notification\NotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class TokovoucherWebhookTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('supplier.tokovoucher.member_code', 'MEM123');
        config()->set('supplier.tokovoucher.secret_key', 'SEC456');
    }

    private function transaction(string $status = 'processing'): Transaction
    {
        $category = Category::create(['name' => 'Games', 'slug' => 'games']);
        $product = Product::create([
            'category_id' => $category->id,
            'name' => 'Free Fire',
            'slug' => 'free-fire',
            'supplier_type' => 'tokovoucher',
            'supplier_code' => 'free-fire',
        ]);
        $item = ProductItem::create([
            'product_id' => $product->id,
            'name' => '50 Diamonds',
            'supplier_item_code' => 'FF50',
            'buy_price' => '6000',
            'sell_price' => '7000',
            'is_active' => true,
        ]);

        return Transaction::create([
            'invoice_id' => 'INV-TV-WEBHOOK-1',
            'product_item_id' => $item->id,
            'customer_data' => ['user_id' => '12345678'],
            'customer_phone' => '081234567890',
            'amount' => '7000',
            'total' => '7000',
            'payment_gateway' => 'belibayar',
            'payment_channel' => 'QRIS',
            'status' => $status,
        ]);
    }

    public function test_rejects_invalid_authorization_header(): void
    {
        $this->transaction();

        $payload = [
            'status' => 'sukses',
            'ref_id' => 'INV-TV-WEBHOOK-1',
            'trx_id' => 'T123',
            'sn' => 'SN123',
        ];

        $response = $this->postJson('/webhook/tokovoucher', $payload, [
            'X-TokoVoucher-Authorization' => 'invalid-auth',
        ]);

        $response->assertStatus(403);
    }

    public function test_successful_webhook_marks_transaction_completed_and_triggers_notification(): void
    {
        $trx = $this->transaction('processing');

        $notifications = Mockery::mock(NotificationService::class);
        $notifications->shouldReceive('orderCompleted')
            ->once()
            ->withArgs(fn ($t) => $t->id === $trx->id);
        $this->app->instance(NotificationService::class, $notifications);

        $payload = [
            'status' => 'sukses',
            'message' => 'Transaksi berhasil',
            'ref_id' => 'INV-TV-WEBHOOK-1',
            'trx_id' => 'T123456',
            'sn' => 'SN998877',
        ];

        $authHeader = md5('MEM123:SEC456:INV-TV-WEBHOOK-1');

        $response = $this->postJson('/webhook/tokovoucher', $payload, [
            'X-TokoVoucher-Authorization' => $authHeader,
        ]);

        $response->assertOk();

        $fresh = $trx->fresh();
        $this->assertSame('completed', $fresh->status);
        $this->assertSame('T123456', $fresh->supplier_ref);
        $this->assertSame('SN998877', $fresh->supplier_sn);
        $this->assertNotNull($fresh->completed_at);
    }

    public function test_failed_webhook_marks_transaction_failed(): void
    {
        $trx = $this->transaction('processing');

        $payload = [
            'status' => 'gagal',
            'message' => 'Nomor tujuan tidak valid',
            'ref_id' => 'INV-TV-WEBHOOK-1',
            'trx_id' => 'T123456',
            'sn' => '',
        ];

        $authHeader = md5('MEM123:SEC456:INV-TV-WEBHOOK-1');

        $response = $this->postJson('/webhook/tokovoucher', $payload, [
            'X-TokoVoucher-Authorization' => $authHeader,
        ]);

        $response->assertOk();

        $fresh = $trx->fresh();
        $this->assertSame('failed', $fresh->status);
    }
}
