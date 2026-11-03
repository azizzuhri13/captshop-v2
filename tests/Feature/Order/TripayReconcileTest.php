<?php

namespace Tests\Feature\Order;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductItem;
use App\Models\Transaction;
use App\Services\Payment\TripayClient;
use App\Services\Supplier\SupplierInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Jaring pengaman Tripay: transaksi pending/expired yang callbacnya hilang
 * di-inquiry langsung ke /transaction/detail.
 *
 * Fokus: hanya detail yang cocok persis (reference, merchant_ref, nominal,
 * channel) yang boleh mengubah status; satu klaim paid; tidak ada topup ganda.
 */
class TripayReconcileTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('payment.tripay.enabled', true);
        config()->set('payment.tripay.mode', 'sandbox');
        config()->set('payment.tripay.private_key', 'tripay-private-test-key');
        config()->set('payment.tripay.merchant_code', 'T12345');
        config()->set('payment.tripay.channels', [
            'QRIS' => 'QRIS',
            'BCA' => 'BCAVA',
        ]);
    }

    private function transaction(
        string $invoice = 'INV-TRIPAY-RECON-1',
        string $ref = 'T-PROVIDER-1',
        string $status = 'pending',
        string $gateway = 'tripay',
    ): Transaction {
        $category = Category::firstOrCreate(['slug' => 'games'], ['name' => 'Games']);
        $product = Product::firstOrCreate(
            ['slug' => 'test-game'],
            ['category_id' => $category->id, 'name' => 'Test Game', 'supplier_type' => 'digiflazz'],
        );
        $item = ProductItem::firstOrCreate(
            ['product_id' => $product->id, 'name' => 'Voucher'],
            ['supplier_item_code' => 'ml-10', 'buy_price' => '12000', 'sell_price' => '15000'],
        );

        $transaction = Transaction::create([
            'invoice_id' => $invoice,
            'product_item_id' => $item->id,
            'customer_data' => ['user_id' => '123'],
            'customer_phone' => '081234567890',
            'customer_email' => 'buyer@example.com',
            'amount' => '15000',
            'fee' => '0',
            'total' => '15000',
            'profit' => '3000',
            'payment_gateway' => $gateway,
            'payment_channel' => 'BCA',
            'payment_ref' => $ref,
            'status' => $status,
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

        // Rekonsiliasi hanya menyentuh transaksi yang sudah lewat 2 menit.
        $transaction->forceFill(['created_at' => now()->subMinutes(10)])->save();

        return $transaction;
    }

    private function detail(array $overrides = []): array
    {
        return array_merge([
            'reference' => 'T-PROVIDER-1',
            'merchant_ref' => 'INV-TRIPAY-RECON-1',
            'payment_method' => 'BCA Virtual Account',
            'payment_method_code' => 'BCAVA',
            'amount' => 15000,
            'fee_merchant' => 0,
            'fee_customer' => 700,
            'total_fee' => 700,
            'amount_received' => 15000,
            'is_closed_payment' => 1,
            'status' => 'PAID',
        ], $overrides);
    }

    private function fakeDetail(array $detail): void
    {
        $this->mock(TripayClient::class, function ($mock) use ($detail) {
            $mock->shouldReceive('transactionDetail')->andReturn($detail);
        });
    }

    private function supplierCompleting(int $times = 1)
    {
        $supplier = $this->mock(SupplierInterface::class);
        $supplier->shouldReceive('supplierName')->andReturn('digiflazz');
        $supplier->shouldReceive('topup')->times($times)->andReturn([
            'supplier_ref' => 'DF-TRIPAY-1',
            'supplier_sn' => 'SN-TRIPAY-1',
            'status' => 'completed',
            'raw' => [],
        ]);
        $this->app->instance(SupplierInterface::class, $supplier);

        return $supplier;
    }

    public function test_recovers_pending_transaction_when_detail_matches_exactly(): void
    {
        $this->supplierCompleting();
        $transaction = $this->transaction();
        $this->fakeDetail($this->detail());

        $this->artisan('payments:reconcile-tripay')->assertExitCode(0);

        $fresh = $transaction->fresh();
        $this->assertSame('completed', $fresh->status);
        $this->assertNotNull($fresh->paid_at);
        $this->assertSame('SN-TRIPAY-1', $fresh->supplier_sn);
    }

    public function test_recovers_late_paid_transaction_already_expired_locally(): void
    {
        $this->supplierCompleting();
        $transaction = $this->transaction('INV-TRIPAY-RECON-2', 'T-PROVIDER-2', 'expired');
        $this->fakeDetail($this->detail([
            'reference' => 'T-PROVIDER-2',
            'merchant_ref' => 'INV-TRIPAY-RECON-2',
        ]));

        $this->artisan('payments:reconcile-tripay')->assertExitCode(0);

        $this->assertSame('completed', $transaction->fresh()->status);
    }

    public function test_leaves_pending_transaction_when_provider_still_unpaid(): void
    {
        $this->mock(SupplierInterface::class)->shouldNotReceive('topup');
        $transaction = $this->transaction();
        $this->fakeDetail($this->detail(['status' => 'UNPAID']));

        $this->artisan('payments:reconcile-tripay')->assertExitCode(0);

        $fresh = $transaction->fresh();
        $this->assertSame('pending', $fresh->status);
        $this->assertNull($fresh->paid_at);
    }

    public function test_does_not_recover_when_detail_amount_differs(): void
    {
        $this->mock(SupplierInterface::class)->shouldNotReceive('topup');
        $transaction = $this->transaction();
        $this->fakeDetail($this->detail(['amount' => 14000, 'fee_customer' => 0]));

        $this->artisan('payments:reconcile-tripay')->assertExitCode(0);

        $fresh = $transaction->fresh();
        $this->assertSame('pending', $fresh->status);
        $this->assertNull($fresh->paid_at);
    }

    public function test_does_not_recover_when_detail_payable_differs_from_stored_payable(): void
    {
        $this->mock(SupplierInterface::class)->shouldNotReceive('topup');
        $transaction = $this->transaction();
        // Amount pesanan cocok, tetapi fee pelanggan berbeda sehingga nominal
        // yang benar-benar dibayar tidak sama dengan yang ditagih.
        $this->fakeDetail($this->detail(['fee_customer' => 500]));

        $this->artisan('payments:reconcile-tripay')->assertExitCode(0);

        $this->assertSame('pending', $transaction->fresh()->status);
    }

    public function test_does_not_recover_when_detail_reference_differs(): void
    {
        $this->mock(SupplierInterface::class)->shouldNotReceive('topup');
        $transaction = $this->transaction();
        $this->fakeDetail($this->detail(['reference' => 'T-PROVIDER-OTHER']));

        $this->artisan('payments:reconcile-tripay')->assertExitCode(0);

        $fresh = $transaction->fresh();
        $this->assertSame('pending', $fresh->status);
        $this->assertNull($fresh->paid_at);
    }

    public function test_does_not_recover_when_detail_merchant_ref_differs(): void
    {
        $this->mock(SupplierInterface::class)->shouldNotReceive('topup');
        $transaction = $this->transaction();
        $this->fakeDetail($this->detail(['merchant_ref' => 'INV-OTHER']));

        $this->artisan('payments:reconcile-tripay')->assertExitCode(0);

        $this->assertSame('pending', $transaction->fresh()->status);
    }

    public function test_does_not_recover_when_detail_channel_differs(): void
    {
        $this->mock(SupplierInterface::class)->shouldNotReceive('topup');
        $transaction = $this->transaction();
        $this->fakeDetail($this->detail([
            'payment_method' => 'QRIS',
            'payment_method_code' => 'QRIS',
        ]));

        $this->artisan('payments:reconcile-tripay')->assertExitCode(0);

        $this->assertSame('pending', $transaction->fresh()->status);
    }

    public function test_does_not_recover_when_detail_is_not_closed_payment(): void
    {
        $this->mock(SupplierInterface::class)->shouldNotReceive('topup');
        $transaction = $this->transaction();
        $this->fakeDetail($this->detail(['is_closed_payment' => 0]));

        $this->artisan('payments:reconcile-tripay')->assertExitCode(0);

        $this->assertSame('pending', $transaction->fresh()->status);
    }

    public function test_marks_pending_transaction_expired_when_provider_reports_expired(): void
    {
        $this->mock(SupplierInterface::class)->shouldNotReceive('topup');
        $transaction = $this->transaction();
        $this->fakeDetail($this->detail(['status' => 'EXPIRED']));

        $this->artisan('payments:reconcile-tripay')->assertExitCode(0);

        $this->assertSame('expired', $transaction->fresh()->status);
    }

    public function test_duplicate_runs_never_call_supplier_twice(): void
    {
        $this->supplierCompleting(1);
        $transaction = $this->transaction();
        $this->fakeDetail($this->detail());

        $this->artisan('payments:reconcile-tripay')->assertExitCode(0);
        $this->artisan('payments:reconcile-tripay')->assertExitCode(0);

        $fresh = $transaction->fresh();
        $this->assertSame('completed', $fresh->status);
        $this->assertSame(1, (int) $fresh->supplier_attempts);
    }

    public function test_ignores_transactions_from_other_gateways(): void
    {
        $this->mock(TripayClient::class)->shouldNotReceive('transactionDetail');
        $this->mock(SupplierInterface::class)->shouldNotReceive('topup');

        $transaction = $this->transaction('INV-BELIBAYAR-1', 'QR-BELIBAYAR-1', 'pending', 'belibayar');

        $this->artisan('payments:reconcile-tripay')->assertExitCode(0);

        $this->assertSame('pending', $transaction->fresh()->status);
    }

    public function test_provider_error_does_not_change_status(): void
    {
        $this->mock(TripayClient::class, function ($mock) {
            $mock->shouldReceive('transactionDetail')->andThrow(
                new \App\Services\Payment\PaymentException('Koneksi detail Tripay gagal atau timeout; coba lagi beberapa saat.')
            );
        });
        $this->mock(SupplierInterface::class)->shouldNotReceive('topup');

        $transaction = $this->transaction();

        $this->artisan('payments:reconcile-tripay')->assertExitCode(0);

        $fresh = $transaction->fresh();
        $this->assertSame('pending', $fresh->status);
        $this->assertNull($fresh->paid_at);
    }
}