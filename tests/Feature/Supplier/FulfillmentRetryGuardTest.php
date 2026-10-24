<?php

namespace Tests\Feature\Supplier;

use App\Models\Admin;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductItem;
use App\Models\Transaction;
use App\Services\FulfillmentService;
use App\Services\Notification\NotificationService;
use App\Services\Notification\TelegramAlertService;
use App\Services\Supplier\DigiflazzClient;
use App\Services\Supplier\DigiflazzService;
use App\Services\Supplier\SupplierException;
use App\Services\Supplier\SupplierInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Guard terminal-failure supplier.
 *
 * Aturan yang diuji:
 * 1. Supplier menyatakan gagal final (mis. DigiFlazz rc 02 "Transaksi Gagal")
 *    → order ditahan (status failed + needs_manual_review), TIDAK di-retry.
 * 2. Percobaan otomatis dibatasi `supplier.max_attempts`; setelah habis order
 *    ditahan, bukan dikirim ulang tanpa batas oleh scheduler.
 * 3. Order yang sudah ditahan tidak pernah dihubungi supplier lagi, dan
 *    orders:reconcile melewatinya serta menghormati jeda (backoff).
 * 4. Admin tetap bisa memicu retry manual secara eksplisit.
 */
class FulfillmentRetryGuardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'supplier.max_attempts' => 3,
            'supplier.retry_backoff_minutes' => [5, 15, 60],
        ]);

        $this->mock(NotificationService::class, function ($mock) {
            $mock->shouldReceive('paymentReceived')->andReturnNull();
            $mock->shouldReceive('orderCompleted')->andReturnNull();
        });

        $this->mock(TelegramAlertService::class, function ($mock) {
            $mock->shouldReceive('alertFulfillmentFailed')->andReturnNull();
            $mock->shouldReceive('send')->andReturnNull();
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function transaction(array $attributes = []): Transaction
    {
        $category = Category::firstOrCreate(['slug' => 'games'], ['name' => 'Games']);
        $product = Product::firstOrCreate(
            ['slug' => 'test-game'],
            [
                'category_id' => $category->id,
                'name' => 'Test Game',
                'slug' => 'test-game',
                'supplier_type' => 'digiflazz',
                'is_active' => true,
            ],
        );
        $item = ProductItem::firstOrCreate(
            ['product_id' => $product->id, 'name' => 'Voucher'],
            [
                'supplier_item_code' => 'ml-296',
                'buy_price' => '50000',
                'sell_price' => '58200',
                'is_active' => true,
            ],
        );

        return Transaction::create(array_merge([
            'invoice_id' => 'INV-GUARD-'.uniqid(),
            'product_item_id' => $item->id,
            'customer_data' => ['user_id' => '111632622', 'zone_id' => '2559'],
            'customer_phone' => '081234567890',
            'amount' => '58200',
            'fee' => '0',
            'total' => '58200',
            'profit' => '8200',
            'payment_gateway' => 'belibayar',
            'payment_channel' => 'QRIS',
            'payment_ref' => 'QR-'.uniqid(),
            'status' => 'paid',
        ], $attributes));
    }

    private function transactionsOlderThanReconcileWindow(Transaction $transaction): Transaction
    {
        $transaction->forceFill(['created_at' => now()->subMinutes(10)])->save();

        return $transaction;
    }

    public function test_terminal_supplier_failure_holds_order_for_manual_review(): void
    {
        $supplier = $this->mock(SupplierInterface::class);
        $supplier->shouldReceive('supplierName')->andReturn('digiflazz');
        $supplier->shouldReceive('topup')->once()->andThrow(
            SupplierException::terminalFailure('DigiFlazz /transaction error: Transaksi Gagal (rc 02)')
        );
        $this->app->instance(SupplierInterface::class, $supplier);

        $transaction = $this->transaction();

        app(FulfillmentService::class)->fulfill($transaction);

        $fresh = $transaction->fresh();
        $this->assertSame('failed', $fresh->status);
        $this->assertTrue((bool) $fresh->needs_manual_review);
        $this->assertStringContainsString('rc 02', (string) $fresh->manual_review_reason);
        $this->assertSame(1, (int) $fresh->supplier_attempts);
        $this->assertSame('digiflazz', $fresh->supplier_name);
        $this->assertNotNull($fresh->supplier_last_attempt_at);
    }

    public function test_retryable_supplier_failure_keeps_order_processing_and_counts_attempt(): void
    {
        $supplier = $this->mock(SupplierInterface::class);
        $supplier->shouldReceive('supplierName')->andReturn('digiflazz');
        $supplier->shouldReceive('topup')->once()->andThrow(
            SupplierException::requestFailed('DigiFlazz /transaction gagal (503): upstream timeout', true)
        );
        $this->app->instance(SupplierInterface::class, $supplier);

        $transaction = $this->transaction();

        app(FulfillmentService::class)->fulfill($transaction);

        $fresh = $transaction->fresh();
        $this->assertSame('processing', $fresh->status);
        $this->assertFalse((bool) $fresh->needs_manual_review);
        $this->assertSame(1, (int) $fresh->supplier_attempts);
    }

    public function test_order_is_held_when_attempt_limit_reached_without_calling_supplier(): void
    {
        $supplier = $this->mock(SupplierInterface::class);
        $supplier->shouldNotReceive('topup');
        $supplier->shouldNotReceive('supplierName');
        $this->app->instance(SupplierInterface::class, $supplier);

        $transaction = $this->transaction([
            'status' => 'processing',
            'supplier_attempts' => 3,
            'supplier_last_attempt_at' => now()->subHour(),
        ]);

        app(FulfillmentService::class)->fulfill($transaction);

        $fresh = $transaction->fresh();
        $this->assertSame('failed', $fresh->status);
        $this->assertTrue((bool) $fresh->needs_manual_review);
        $this->assertStringContainsString('batas', (string) $fresh->manual_review_reason);
    }

    public function test_held_order_is_never_sent_to_supplier_again(): void
    {
        $supplier = $this->mock(SupplierInterface::class);
        $supplier->shouldNotReceive('topup');
        $supplier->shouldNotReceive('supplierName');
        $this->app->instance(SupplierInterface::class, $supplier);

        $transaction = $this->transaction([
            'status' => 'processing',
            'supplier_attempts' => 1,
            'supplier_last_attempt_at' => now()->subHours(2),
            'needs_manual_review' => true,
            'manual_review_reason' => 'DigiFlazz menolak final (rc 02).',
        ]);

        app(FulfillmentService::class)->fulfill($transaction);

        $fresh = $transaction->fresh();
        $this->assertSame('processing', $fresh->status);
        $this->assertSame(1, (int) $fresh->supplier_attempts);
        $this->assertSame('DigiFlazz menolak final (rc 02).', $fresh->manual_review_reason);
    }

    public function test_admin_manual_retry_reopens_held_order(): void
    {
        $supplier = $this->mock(SupplierInterface::class);
        $supplier->shouldReceive('supplierName')->andReturn('digiflazz');
        $supplier->shouldReceive('topup')->once()->andReturn([
            'supplier_ref' => 'DF-99', 'supplier_sn' => 'SN-99', 'status' => 'completed', 'raw' => [],
        ]);
        $this->app->instance(SupplierInterface::class, $supplier);

        $transaction = $this->transaction([
            'status' => 'failed',
            'supplier_attempts' => 3,
            'needs_manual_review' => true,
            'manual_review_reason' => 'Percobaan otomatis supplier habis.',
        ]);

        app(FulfillmentService::class)->retryManually($transaction);

        $fresh = $transaction->fresh();
        $this->assertSame('completed', $fresh->status);
        $this->assertFalse((bool) $fresh->needs_manual_review);
        $this->assertNull($fresh->manual_review_reason);
        $this->assertSame(1, (int) $fresh->supplier_attempts);
        $this->assertSame('SN-99', $fresh->supplier_sn);
    }

    public function test_reconcile_skips_order_held_for_manual_review(): void
    {
        $service = $this->mock(DigiflazzService::class);
        $service->shouldNotReceive('topup');
        $service->shouldNotReceive('supplierName');
        $this->app->instance(DigiflazzService::class, $service);

        $transaction = $this->transactionsOlderThanReconcileWindow($this->transaction([
            'status' => 'processing',
            'supplier_attempts' => 1,
            'supplier_last_attempt_at' => now()->subHours(2),
            'needs_manual_review' => true,
            'manual_review_reason' => 'DigiFlazz menolak final (rc 02).',
        ]));

        $this->artisan('orders:reconcile')->assertExitCode(0);

        $fresh = $transaction->fresh();
        $this->assertSame('processing', $fresh->status);
        $this->assertSame(1, (int) $fresh->supplier_attempts);
    }

    public function test_reconcile_respects_backoff_between_attempts(): void
    {
        $service = $this->mock(DigiflazzService::class);
        $service->shouldNotReceive('topup');
        $this->app->instance(DigiflazzService::class, $service);

        $transaction = $this->transactionsOlderThanReconcileWindow($this->transaction([
            'status' => 'processing',
            'supplier_attempts' => 1,
            // Percobaan pertama hanya berjeda 5 menit; belum boleh dikirim ulang.
            'supplier_last_attempt_at' => now()->subMinute(),
        ]));

        $this->artisan('orders:reconcile')->assertExitCode(0);

        $fresh = $transaction->fresh();
        $this->assertSame('processing', $fresh->status);
        $this->assertSame(1, (int) $fresh->supplier_attempts);
    }

    public function test_reconcile_holds_order_when_attempt_limit_reached(): void
    {
        $service = $this->mock(DigiflazzService::class);
        $service->shouldNotReceive('topup');
        $service->shouldNotReceive('supplierName');
        $this->app->instance(DigiflazzService::class, $service);

        $transaction = $this->transactionsOlderThanReconcileWindow($this->transaction([
            'status' => 'processing',
            'supplier_attempts' => 3,
            'supplier_last_attempt_at' => now()->subDays(1),
        ]));

        $this->artisan('orders:reconcile')->assertExitCode(0);

        $fresh = $transaction->fresh();
        $this->assertSame('failed', $fresh->status);
        $this->assertTrue((bool) $fresh->needs_manual_review);
    }

    public function test_reconcile_holds_order_when_supplier_reports_terminal_failure(): void
    {
        $service = $this->mock(DigiflazzService::class);
        $service->shouldReceive('supplierName')->andReturn('digiflazz');
        $service->shouldReceive('topup')->once()->andThrow(
            SupplierException::terminalFailure('DigiFlazz /transaction error: Transaksi Gagal (rc 02)')
        );
        $this->app->instance(DigiflazzService::class, $service);

        $transaction = $this->transactionsOlderThanReconcileWindow($this->transaction([
            'status' => 'processing',
            'supplier_attempts' => 1,
            'supplier_last_attempt_at' => now()->subMinutes(30),
        ]));

        $this->artisan('orders:reconcile')->assertExitCode(0);

        $fresh = $transaction->fresh();
        $this->assertSame('failed', $fresh->status);
        $this->assertTrue((bool) $fresh->needs_manual_review);
        $this->assertStringContainsString('rc 02', (string) $fresh->manual_review_reason);
        $this->assertSame(2, (int) $fresh->supplier_attempts);
        $this->assertSame('digiflazz', $fresh->supplier_name);
    }

    public function test_reconcile_holds_order_when_supplier_returns_failed_status(): void
    {
        $service = $this->mock(DigiflazzService::class);
        $service->shouldReceive('supplierName')->andReturn('digiflazz');
        $service->shouldReceive('topup')->once()->andReturn([
            'supplier_ref' => null,
            'supplier_sn' => null,
            'status' => 'failed',
            'message' => 'Transaksi Gagal',
            'raw' => ['data' => ['status' => 'Gagal']],
        ]);
        $this->app->instance(DigiflazzService::class, $service);

        $transaction = $this->transactionsOlderThanReconcileWindow($this->transaction([
            'status' => 'processing',
            'supplier_attempts' => 1,
            'supplier_last_attempt_at' => now()->subMinutes(30),
        ]));

        $this->artisan('orders:reconcile')->assertExitCode(0);

        $fresh = $transaction->fresh();
        $this->assertSame('failed', $fresh->status);
        $this->assertTrue((bool) $fresh->needs_manual_review);
        $this->assertStringContainsString('Transaksi Gagal', (string) $fresh->manual_review_reason);
    }

    public function test_reconcile_records_attempt_and_supplier_on_success(): void
    {
        $service = $this->mock(DigiflazzService::class);
        $service->shouldReceive('supplierName')->andReturn('digiflazz');
        $service->shouldReceive('topup')->once()->andReturn([
            'supplier_ref' => 'DF-1', 'supplier_sn' => 'SN-1', 'status' => 'completed', 'raw' => [],
        ]);
        $this->app->instance(DigiflazzService::class, $service);

        $transaction = $this->transactionsOlderThanReconcileWindow($this->transaction([
            'status' => 'processing',
        ]));

        $this->artisan('orders:reconcile')->assertExitCode(0);

        $fresh = $transaction->fresh();
        $this->assertSame('completed', $fresh->status);
        $this->assertSame(1, (int) $fresh->supplier_attempts);
        $this->assertSame('digiflazz', $fresh->supplier_name);
        $this->assertFalse((bool) $fresh->needs_manual_review);
    }

    public function test_admin_panel_surfaces_manual_review_reason(): void
    {
        $admin = Admin::create([
            'name' => 'Test Admin',
            'email' => 'admin@test.com',
            'password' => 'secret-password',
            'role' => 'super_admin',
            'is_active' => true,
        ]);
        $this->actingAs($admin, 'admin');

        $transaction = $this->transaction([
            'status' => 'failed',
            'supplier_attempts' => 2,
            'supplier_name' => 'digiflazz',
            'needs_manual_review' => true,
            'manual_review_reason' => 'DigiFlazz menolak final (rc 02 / Transaksi Gagal).',
        ]);

        $this->get(route('admin.transactions.show', $transaction))
            ->assertOk()
            ->assertSee('Ditahan untuk tindakan admin', false)
            ->assertSee('DigiFlazz menolak final (rc 02 / Transaksi Gagal).', false);

        $this->get(route('admin.transactions.index'))
            ->assertOk()
            ->assertSee('perlu tindakan', false);
    }

    public function test_admin_can_retry_held_order_through_admin_panel(): void
    {
        $admin = Admin::create([
            'name' => 'Test Admin',
            'email' => 'admin@test.com',
            'password' => 'secret-password',
            'role' => 'super_admin',
            'is_active' => true,
        ]);
        $this->actingAs($admin, 'admin');

        $supplier = $this->mock(SupplierInterface::class);
        $supplier->shouldReceive('supplierName')->andReturn('digiflazz');
        $supplier->shouldReceive('topup')->once()->andReturn([
            'supplier_ref' => 'DF-77', 'supplier_sn' => 'SN-77', 'status' => 'completed', 'raw' => [],
        ]);
        $this->app->instance(SupplierInterface::class, $supplier);

        $transaction = $this->transaction([
            'status' => 'failed',
            'supplier_attempts' => 3,
            'needs_manual_review' => true,
            'manual_review_reason' => 'Percobaan otomatis supplier habis.',
        ]);

        $this->post(route('admin.transactions.retry-fulfillment', $transaction))
            ->assertRedirect();

        $fresh = $transaction->fresh();
        $this->assertSame('completed', $fresh->status);
        $this->assertFalse((bool) $fresh->needs_manual_review);
    }

    public function test_digiflazz_client_treats_rc_02_as_terminal_failure(): void
    {
        $this->configureDigiflazz();

        Http::fake([
            'api.digiflazz.test/*' => Http::response([
                'data' => ['rc' => '02', 'status' => 'Gagal', 'message' => 'Transaksi Gagal'],
            ], 200),
        ]);

        try {
            app(DigiflazzClient::class)->post('/transaction', []);
            $this->fail('Gagal final dari supplier tidak terdeteksi sebagai terminal.');
        } catch (SupplierException $e) {
            $this->assertFalse($e->isRetryable());
            $this->assertStringContainsString('rc 02', $e->getMessage());
        }
    }

    public function test_digiflazz_client_treats_server_error_as_retryable(): void
    {
        $this->configureDigiflazz();

        Http::fake([
            'api.digiflazz.test/*' => Http::response(['message' => 'Internal Server Error'], 503),
        ]);

        try {
            app(DigiflazzClient::class)->post('/transaction', []);
            $this->fail('HTTP 503 seharusnya dianggap gagal sementara.');
        } catch (SupplierException $e) {
            $this->assertTrue($e->isRetryable());
        }
    }

    public function test_digiflazz_client_treats_client_error_as_not_retryable(): void
    {
        $this->configureDigiflazz();

        Http::fake([
            'api.digiflazz.test/*' => Http::response(['data' => ['rc' => '99', 'message' => 'Bad request']], 422),
        ]);

        try {
            app(DigiflazzClient::class)->post('/transaction', []);
            $this->fail('HTTP 422 seharusnya tidak di-retry.');
        } catch (SupplierException $e) {
            $this->assertFalse($e->isRetryable());
        }
    }

    private function configureDigiflazz(): void
    {
        config([
            'supplier.digiflazz.username' => 'user-test',
            'supplier.digiflazz.api_key' => 'key-test',
            'supplier.digiflazz.base_url' => 'https://api.digiflazz.test/v1',
        ]);
    }
}
