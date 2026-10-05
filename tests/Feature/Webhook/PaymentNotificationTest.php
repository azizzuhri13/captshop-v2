<?php

namespace Tests\Feature\Webhook;

use App\Mail\InvoiceMail;
use App\Mail\ReceiptMail;
use App\Models\Category;
use App\Models\PaymentChannel;
use App\Models\Product;
use App\Models\ProductItem;
use App\Models\Transaction;
use App\Services\Notification\NotificationService;
use App\Services\Payment\PaymentGatewayInterface;
use App\Services\Supplier\SupplierInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class PaymentNotificationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('payment.tripay.private_key', 'test-private-key');
    }

    protected function createPendingTransaction(): Transaction
    {
        $category = Category::create(['name' => 'Games', 'slug' => 'games']);

        $product = Product::create([
            'category_id' => $category->id,
            'name' => 'Mobile Legends',
            'slug' => 'mobile-legends',
        ]);

        $item = ProductItem::create([
            'product_id' => $product->id,
            'name' => '86 Diamonds',
            'buy_price' => '13000',
            'sell_price' => '15000',
        ]);

        return Transaction::create([
            'invoice_id' => 'INV-20260908-NOTIF01',
            'product_item_id' => $item->id,
            'customer_data' => ['user_id' => '12345'],
            'customer_phone' => '081234567890',
            'customer_email' => 'buyer@example.com',
            'amount' => '15000',
            'fee' => '105',
            'total' => '15105',
            'profit' => '2000',
            'payment_method' => 'QRIS',
            'payment_channel' => 'QRIS',
            'status' => 'pending',
        ]);
    }

    protected function mockSupplierCompleted(): void
    {
        $supplier = $this->mock(SupplierInterface::class);
        $supplier->shouldReceive('topup')->once()->andReturn([
            'supplier_ref' => 'DF-REF-1',
            'supplier_sn' => 'SN-987654321',
            'status' => 'completed',
            'raw' => ['data' => ['status' => 'Sukses']],
        ]);
        $this->app->instance(SupplierInterface::class, $supplier);
    }

    protected function postPaidWebhook(Transaction $transaction)
    {
        $payload = [
            'merchant_ref' => $transaction->invoice_id,
            'status' => 'PAID',
            'amount' => 15105,
        ];

        return $this->postJson('/webhook/tripay', $payload, [
            'X-Callback-Signature' => hash_hmac('sha256', $transaction->invoice_id.'PAID15105', 'test-private-key'),
        ]);
    }

    public function test_order_creation_sends_invoice_email_and_whatsapp(): void
    {
        Mail::fake();

        $category = Category::create(['name' => 'Games', 'slug' => 'games']);

        $product = Product::create([
            'category_id' => $category->id,
            'name' => 'Mobile Legends',
            'slug' => 'mobile-legends',
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

        PaymentChannel::create([
            'gateway' => 'tripay',
            'code' => 'QRIS',
            'name' => 'QRIS',
            'group' => 'QRIS',
            'flat_fee' => '0',
            'percent_fee' => '0.70',
            'is_active' => true,
        ]);

        $gateway = $this->mock(PaymentGatewayInterface::class);
        $gateway->shouldReceive('gatewayName')->andReturn('tripay');
        $gateway->shouldReceive('createTransaction')->andReturn(['reference' => 'REF-1']);
        $this->app->instance(PaymentGatewayInterface::class, $gateway);

        $this->post('/order', [
            'product_item_id' => $item->id,
            'payment_channel' => 'QRIS',
            'customer_data' => ['user_id' => '12345'],
            'customer_phone' => '081234567890',
            'customer_email' => 'buyer@example.com',
        ]);

        $transaction = Transaction::query()->first();

        $this->assertNotNull($transaction);

        // Email invoice terkirim; WhatsApp aman di-skip (token Fonnte kosong di testing).
        Mail::assertSent(InvoiceMail::class, fn ($mail) => $mail->transaction->invoice_id === $transaction->invoice_id);
        Mail::assertSent(InvoiceMail::class, 1);
    }

    public function test_paid_webhook_sends_paid_notification_and_receipt(): void
    {
        Mail::fake();

        $transaction = $this->createPendingTransaction();

        $this->mockSupplierCompleted();

        $this->postPaidWebhook($transaction)->assertOk();

        $transaction = $transaction->refresh();

        $this->assertSame('completed', $transaction->status);
        $this->assertNotNull($transaction->paid_at);

        // Email bukti pembayaran terkirim (paid & completed masing-masing satu).
        Mail::assertSent(ReceiptMail::class, 2);
        Mail::assertSent(ReceiptMail::class, fn ($mail) => $mail->transaction->invoice_id === 'INV-20260908-NOTIF01');
    }

    public function test_notification_failure_does_not_break_fulfillment(): void
    {
        Mail::fake();

        $transaction = $this->createPendingTransaction();

        // NotificationService melempar exception: fulfillment harus tetap sukses.
        $this->mock(NotificationService::class)
            ->shouldReceive('paymentReceived', 'orderCompleted')
            ->andThrow(new \RuntimeException('notification down'));

        $this->mockSupplierCompleted();

        $this->postPaidWebhook($transaction)->assertOk();

        $transaction = $transaction->refresh();

        $this->assertSame('completed', $transaction->status);
        $this->assertSame('SN-987654321', $transaction->supplier_sn);
    }
}
