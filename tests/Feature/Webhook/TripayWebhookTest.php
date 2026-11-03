<?php

namespace Tests\Feature\Webhook;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductItem;
use App\Models\Transaction;
use App\Services\Supplier\SupplierInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Callback Tripay closed payment.
 *
 * Fokus: autentikasi raw-body, kecocokan identitas/nominal/channel, dan
 * idempotensi `paid` (supplier tidak boleh dipanggil dua kali).
 */
class TripayWebhookTest extends TestCase
{
    use RefreshDatabase;

    private const PRIVATE_KEY = 'tripay-private-test-key';

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('payment.tripay.enabled', true);
        config()->set('payment.tripay.mode', 'sandbox');
        config()->set('payment.tripay.private_key', self::PRIVATE_KEY);
        config()->set('payment.tripay.merchant_code', 'T12345');
        config()->set('payment.tripay.channels', [
            'QRIS' => 'QRIS',
            'BCA' => 'BCAVA',
        ]);
    }

    private function transaction(string $invoice = 'INV-TRIPAY-1', string $ref = 'T-PROVIDER-1'): Transaction
    {
        $category = Category::firstOrCreate(['slug' => 'games'], ['name' => 'Games']);
        $product = Product::firstOrCreate(
            ['slug' => 'test-game'],
            ['category_id' => $category->id, 'name' => 'Test Game', 'supplier_type' => 'digiflazz'],
        );
        $item = ProductItem::firstOrCreate(
            ['product_id' => $product->id, 'name' => 'Voucher'],
            ['supplier_item_code' => 'ml-10', 'buy_price' => '12000', 'sell_price' => '15000'],
        );

        return Transaction::create([
            'invoice_id' => $invoice,
            'product_item_id' => $item->id,
            'customer_data' => ['user_id' => '123'],
            'customer_phone' => '081234567890',
            'customer_email' => 'buyer@example.com',
            'amount' => '15000',
            'fee' => '0',
            'total' => '15000',
            'profit' => '3000',
            'payment_gateway' => 'tripay',
            'payment_channel' => 'BCA',
            'payment_ref' => $ref,
            'status' => 'pending',
            'expired_at' => now()->addHour(),
            'payment_instructions' => [
                'merchant_ref' => $invoice,
                'channel' => 'BCAVA',
                'va_number' => '8808123456789012',
                'qr_content' => null,
                'qr_url' => null,
                'payable_amount' => 15700,
            ],
        ]);
    }

    private function payload(string $status, string $invoice = 'INV-TRIPAY-1', string $ref = 'T-PROVIDER-1'): array
    {
        return [
            'reference' => $ref,
            'merchant_ref' => $invoice,
            'payment_method' => 'BCA Virtual Account',
            'payment_method_code' => 'BCAVA',
            'total_amount' => 15700,
            'fee_merchant' => 0,
            'fee_customer' => 700,
            'total_fee' => 700,
            'amount_received' => 15000,
            'is_closed_payment' => 1,
            'status' => $status,
        ];
    }

    /**
     * Kirim callback dengan signature HMAC SHA256 atas raw body (kontrak Tripay).
     */
    private function notify(array $payload, ?string $signature = null, string $event = 'payment_status', ?string $raw = null)
    {
        $raw ??= json_encode($payload, JSON_UNESCAPED_SLASHES);

        return $this->call('POST', '/webhook/tripay', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_CALLBACK_SIGNATURE' => $signature ?? hash_hmac('sha256', $raw, self::PRIVATE_KEY),
            'HTTP_X_CALLBACK_EVENT' => $event,
        ], $raw);
    }

    private function expectsSingleTopup(): void
    {
        $supplier = $this->mock(SupplierInterface::class);
        $supplier->shouldReceive('supplierName')->andReturn('digiflazz');
        $supplier->shouldReceive('topup')->once()->andReturn([
            'supplier_ref' => 'DF-1',
            'supplier_sn' => 'SN-1',
            'status' => 'completed',
            'raw' => [],
        ]);
        $this->app->instance(SupplierInterface::class, $supplier);
    }

    public function test_rejects_invalid_signature_without_touching_the_order(): void
    {
        $transaction = $this->transaction();

        $this->notify($this->payload('PAID'), 'deadbeef')->assertStatus(403)->assertJson(['success' => false]);

        $transaction->refresh();
        $this->assertSame('pending', $transaction->status);
        $this->assertNull($transaction->paid_at);
    }

    public function test_rejects_malformed_body_and_unrecognized_event(): void
    {
        $transaction = $this->transaction();

        $this->notify([], raw: '{"merchant_ref":', signature: hash_hmac('sha256', '{"merchant_ref":', self::PRIVATE_KEY))
            ->assertStatus(400)
            ->assertJson(['success' => false]);

        $this->notify($this->payload('PAID'), event: 'payment_status_unrecognized')
            ->assertOk()
            ->assertJson(['success' => false]);

        $this->assertSame('pending', $transaction->fresh()->status);
    }

    public function test_rejects_identity_amount_channel_and_open_payment_mismatches(): void
    {
        $transaction = $this->transaction();

        // merchant_ref tidak dikenal
        $this->notify($this->payload('PAID', 'INV-UNKNOWN'))->assertStatus(404);

        // reference provider berbeda dari yang tersimpan
        $this->notify([...$this->payload('PAID'), 'reference' => 'T-OTHER'])->assertStatus(422);

        // total_amount tidak sama dengan payable_amount tersimpan (total + fee)
        $this->notify([...$this->payload('PAID'), 'total_amount' => 15000])->assertStatus(422);
        $this->notify([...$this->payload('PAID'), 'total_amount' => 15699])->assertStatus(422);

        // kode channel tidak sama dengan channel Tripay yang dipilih
        $this->notify([...$this->payload('PAID'), 'payment_method_code' => 'BRIVA'])->assertStatus(422);

        // bukan closed payment
        $this->notify([...$this->payload('PAID'), 'is_closed_payment' => 0])->assertStatus(422);

        // status di luar PAID/FAILED/EXPIRED/REFUND
        $this->notify($this->payload('UNPAID'))->assertStatus(422);

        $transaction->refresh();
        $this->assertSame('pending', $transaction->status);
        $this->assertNull($transaction->paid_at);
    }

    public function test_signed_paid_callback_fulfills_once_and_duplicate_is_idempotent(): void
    {
        $transaction = $this->transaction();
        $this->expectsSingleTopup();

        $this->notify($this->payload('PAID'))->assertOk()->assertJson(['success' => true]);

        $transaction->refresh();
        $this->assertSame('completed', $transaction->status);
        $this->assertNotNull($transaction->paid_at);
        $this->assertSame('SN-1', $transaction->supplier_sn);

        // Callback ulang dari Tripay tidak boleh memicu topup kedua.
        $this->notify($this->payload('PAID'))->assertOk()->assertJson(['success' => true]);
        $this->assertSame('completed', $transaction->fresh()->status);
    }

    public function test_expired_then_late_paid_fulfills_only_once(): void
    {
        $transaction = $this->transaction();
        $this->expectsSingleTopup();

        $this->notify($this->payload('EXPIRED'))->assertOk();
        $this->assertSame('expired', $transaction->fresh()->status);

        $this->notify($this->payload('PAID'))->assertOk();
        $this->notify($this->payload('PAID'))->assertOk();

        $transaction->refresh();
        $this->assertSame('completed', $transaction->status);
    }

    public function test_refund_after_paid_is_acknowledged_without_reversing_the_order(): void
    {
        $transaction = $this->transaction();
        $this->expectsSingleTopup();

        $this->notify($this->payload('PAID'))->assertOk();
        $paidAt = $transaction->fresh()->paid_at;
        $this->assertSame('completed', $transaction->fresh()->status);

        $this->notify($this->payload('REFUND'))->assertOk()->assertJson(['success' => true]);

        $transaction->refresh();
        $this->assertSame('completed', $transaction->status);
        $this->assertSame($paidAt?->toIso8601String(), $transaction->paid_at?->toIso8601String());
        $this->assertFalse($transaction->needs_manual_review);
    }

    public function test_failed_and_expired_never_downgrade_a_paid_order(): void
    {
        $transaction = $this->transaction();
        $this->expectsSingleTopup();

        $this->notify($this->payload('PAID'))->assertOk();

        $this->notify($this->payload('EXPIRED'))->assertOk();
        $this->notify($this->payload('FAILED'))->assertOk();

        $this->assertSame('completed', $transaction->fresh()->status);
    }
}