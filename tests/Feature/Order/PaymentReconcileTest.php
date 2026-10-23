<?php

namespace Tests\Feature\Order;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductItem;
use App\Models\Transaction;
use App\Services\Payment\BelibayarClient;
use App\Services\Supplier\SupplierInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Jaring pengaman pembayaran: transaksi Belibayar yang masih pending/expired
 * direkonsiliasi langsung ke status provider, tanpa bergantung pada webhook.
 */
class PaymentReconcileTest extends TestCase
{
    use RefreshDatabase;

    private function transaction(string $invoice = 'INV-RECON-1', string $ref = 'QR-RECON-1', string $status = 'pending'): Transaction
    {
        $category = Category::firstOrCreate(['slug' => 'games'], ['name' => 'Games']);
        $product = Product::firstOrCreate(['slug' => 'test-game'], ['category_id' => $category->id, 'name' => 'Test Game', 'supplier_type' => 'digiflazz']);
        $item = ProductItem::firstOrCreate(['product_id' => $product->id, 'name' => 'Voucher'], ['buy_price' => '12000', 'sell_price' => '15000']);

        $transaction = Transaction::create([
            'invoice_id' => $invoice, 'product_item_id' => $item->id,
            'customer_data' => ['user_id' => '123'], 'customer_phone' => '081234567890',
            'amount' => '15000', 'fee' => '105', 'total' => '15105', 'profit' => '3000',
            'payment_gateway' => 'belibayar', 'payment_channel' => 'QRIS',
            'payment_ref' => $ref, 'status' => $status,
        ]);

        // Rekonsiliasi hanya menyentuh transaksi yang sudah lewat 2 menit.
        $transaction->forceFill(['created_at' => now()->subMinutes(10)])->save();

        return $transaction;
    }

    private function fakeProvider(array $response): void
    {
        $this->mock(BelibayarClient::class, function ($mock) use ($response) {
            $mock->shouldReceive('status')->andReturn($response);
        });
    }

    private function providerPayload(string $status, int $amount = 15105, string $ref = 'QR-RECON-1'): array
    {
        return [
            'reference' => 'INV-RECON-1',
            'transaction_id' => $ref,
            'payment_channel' => 'QRIS',
            'amount' => $amount,
            'status' => $status,
        ];
    }

    public function test_recovers_pending_transaction_that_provider_reports_paid(): void
    {
        $supplier = $this->mock(SupplierInterface::class);
        $supplier->shouldReceive('topup')->once()->andReturn([
            'supplier_ref' => 'DF-1', 'supplier_sn' => 'SN-1', 'status' => 'completed', 'raw' => [],
        ]);
        $this->app->instance(SupplierInterface::class, $supplier);

        $transaction = $this->transaction();
        $this->fakeProvider($this->providerPayload('paid'));

        $this->artisan('payments:reconcile')->assertExitCode(0);

        $fresh = $transaction->fresh();
        $this->assertSame('completed', $fresh->status);
        $this->assertNotNull($fresh->paid_at);
        $this->assertSame('SN-1', $fresh->supplier_sn);
    }

    public function test_does_not_recover_when_provider_amount_differs(): void
    {
        $transaction = $this->transaction();
        $this->fakeProvider($this->providerPayload('paid', 1000));

        $this->artisan('payments:reconcile')->assertExitCode(0);

        $this->assertSame('pending', $transaction->fresh()->status);
    }

    public function test_does_not_recover_when_provider_reference_differs(): void
    {
        $transaction = $this->transaction();
        $this->fakeProvider($this->providerPayload('paid', 15105, 'QR-OTHER'));

        $this->artisan('payments:reconcile')->assertExitCode(0);

        $this->assertSame('pending', $transaction->fresh()->status);
    }

    public function test_marks_pending_transaction_expired_when_provider_says_expired(): void
    {
        $transaction = $this->transaction();
        $this->fakeProvider($this->providerPayload('expired'));

        $this->artisan('payments:reconcile')->assertExitCode(0);

        $this->assertSame('expired', $transaction->fresh()->status);
    }

    public function test_recovers_late_paid_transaction_that_was_already_expired_locally(): void
    {
        $supplier = $this->mock(SupplierInterface::class);
        $supplier->shouldReceive('topup')->once()->andReturn([
            'supplier_ref' => 'DF-2', 'supplier_sn' => 'SN-2', 'status' => 'completed', 'raw' => [],
        ]);
        $this->app->instance(SupplierInterface::class, $supplier);

        $transaction = $this->transaction('INV-RECON-2', 'QR-RECON-2', 'expired');
        $this->fakeProvider($this->providerPayload('paid', 15105, 'QR-RECON-2'));

        $this->artisan('payments:reconcile')->assertExitCode(0);

        $this->assertSame('completed', $transaction->fresh()->status);
    }

    public function test_skips_transactions_that_are_already_final(): void
    {
        $transaction = $this->transaction('INV-RECON-3', 'QR-RECON-3', 'completed');
        $this->mock(BelibayarClient::class, function ($mock) {
            $mock->shouldNotReceive('status');
        });

        $this->artisan('payments:reconcile')->assertExitCode(0);

        $this->assertSame('completed', $transaction->fresh()->status);
    }
}
