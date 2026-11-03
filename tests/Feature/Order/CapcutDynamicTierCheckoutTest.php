<?php

namespace Tests\Feature\Order;

use App\Models\CapcutAccount;
use App\Models\Category;
use App\Models\PaymentChannel;
use App\Models\Product;
use App\Models\ProductItem;
use App\Models\Transaction;
use App\Services\CapcutTierService;
use App\Services\FulfillmentService;
use App\Services\OrderService;
use App\Services\Payment\PaymentGatewayInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class CapcutDynamicTierCheckoutTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-10-27 03:00:00', 'UTC'));

        $gateway = \Mockery::mock(PaymentGatewayInterface::class);
        $gateway->shouldReceive('gatewayName')->andReturn('belibayar');
        $gateway->shouldReceive('createTransaction')->andReturnUsing(function (Transaction $transaction) {
            return [
                'reference' => $transaction->invoice_id,
                'transaction_id' => 'byrtrx_'.$transaction->id,
                'payment_channel' => 'QRIS',
                'amount' => (int) $transaction->total,
                'status' => 'pending',
                'qr_url' => 'https://link.belibayar.id/test.png',
                'expired_at' => Carbon::now()->addHour()->timestamp,
            ];
        });
        // OrderService sekarang membaca bentuk kanonik dari gateway (bukan field
        // mentah Belibayar), jadi double ini harus meniru kontrak normalizeCharge.
        $gateway->shouldReceive('normalizeCharge')->andReturnUsing(function (array $response, Transaction $transaction) {
            return [
                'merchant_ref' => $response['reference'] ?? null,
                'provider_ref' => $response['transaction_id'] ?? null,
                'merchant_amount' => $response['amount'] ?? null,
                'payable_amount' => $response['amount'] ?? null,
                'channel' => $response['payment_channel'] ?? null,
                'status' => $response['status'] ?? null,
                'expires_at' => $response['expired_at'] ?? null,
                'payment_url' => $response['payment_url'] ?? null,
                'va_number' => $response['va_number'] ?? null,
                'qr_string' => $response['qr_content'] ?? null,
                'qr_url' => $response['qr_url'] ?? null,
            ];
        });
        $this->app->instance(PaymentGatewayInterface::class, $gateway);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** Buat akun CapCut yang sudah masuk stok N hari lalu. */
    private function account(string $email, float $ageDays, string $status = 'ready'): CapcutAccount
    {
        $account = CapcutAccount::create(['email' => $email, 'password' => 'p', 'status' => $status]);
        $account->forceFill(['created_at' => Carbon::now()->subSeconds((int) round($ageDays * 86400))])->save();

        return $account->fresh();
    }

    /** @return array{0: PaymentChannel, 1: ProductItem} */
    private function capcutFixture(string $sellPrice = '25000'): array
    {
        $channel = PaymentChannel::create([
            'gateway' => 'belibayar',
            'code' => 'QRIS',
            'name' => 'QRIS',
            'group' => 'QRIS',
            'flat_fee' => '0',
            'percent_fee' => '0',
            'is_active' => true,
            'sort_order' => 30,
        ]);

        $category = Category::create(['name' => 'Apps Premium', 'slug' => 'apps-premium']);
        $product = Product::create([
            'category_id' => $category->id,
            'name' => 'Capcut',
            'slug' => 'capcut',
            'supplier_type' => 'manual',
            'is_active' => true,
        ]);
        $item = ProductItem::create([
            'product_id' => $product->id,
            'name' => '1 bulan',
            'buy_price' => '10000',
            'sell_price' => $sellPrice,
            'is_active' => true,
        ]);

        return [$channel, $item];
    }

    public function test_tier_boundaries_follow_account_age(): void
    {
        $cases = [
            0 => ['full', 25000],
            8.9 => ['full', 25000],
            9 => ['mid', 20000],
            15.9 => ['mid', 20000],
            16 => ['low', 15000],
            22.9 => ['low', 15000],      // sisa ~7.1 hari, belum clearance
            23 => ['clearance', 10000],  // sisa tepat 7 hari
            29.9 => ['clearance', 10000],
        ];

        foreach ($cases as $age => [$tier, $price]) {
            $info = $this->account("age-{$age}@capcut.com", (float) $age)->tierInfo();

            $this->assertSame($tier, $info['tier'], "tier salah untuk umur {$age} hari");
            $this->assertSame($price, $info['price'], "harga salah untuk umur {$age} hari");
            $this->assertTrue($info['sellable']);
            $this->assertFalse($info['expired']);
        }
    }

    public function test_clearance_rule_wins_over_age_tier(): void
    {
        $info = $this->account('clearance@capcut.com', 24)->tierInfo();

        $this->assertSame('clearance', $info['tier']);
        $this->assertSame(10000, $info['price']);
        $this->assertSame(6, $info['remaining_days']);
        $this->assertSame(Carbon::now()->addDays(6)->toDateString(), $info['valid_until']);
    }

    public function test_account_expires_after_thirty_days_and_cannot_be_sold(): void
    {
        $expired = $this->account('expired@capcut.com', 30);

        $info = $expired->tierInfo();
        $this->assertTrue($info['expired']);
        $this->assertFalse($info['sellable']);
        $this->assertSame(0, $info['remaining_days']);

        $this->assertSame(0, CapcutAccount::query()->sellable()->count());

        // expireStale menandai status agar tidak lagi dianggap stok siap jual.
        $this->assertSame(1, CapcutAccount::expireStale());
        $this->assertSame('expired', $expired->fresh()->status);
    }

    public function test_web_checkout_snapshots_tier_price_and_reserves_exact_account(): void
    {
        [$channel, $item] = $this->capcutFixture();
        $oldest = $this->account('oldest@capcut.com', 23);
        $fresh = $this->account('fresh@capcut.com', 0);

        $this->assertSame(10000, (int) $item->effectivePrice());

        $transaction = app(OrderService::class)->place([
            'product_item_id' => $item->id,
            'payment_channel' => 'QRIS',
            'customer_phone' => '081234567890',
            'customer_email' => 'buyer@test.com',
            'customer_data' => ['email' => 'buyer@test.com'],
        ]);

        $this->assertSame('10000.00', (string) $transaction->fresh()->amount);
        $this->assertSame(10000, (int) $transaction->fresh()->total);

        $oldest->refresh();
        $this->assertSame('reserved', $oldest->status);
        $this->assertSame('web', $oldest->channel);
        $this->assertSame($transaction->id, $oldest->transaction_id);
        $this->assertSame('ready', $fresh->fresh()->status);
    }

    public function test_second_checkout_cannot_take_the_reserved_account(): void
    {
        [, $item] = $this->capcutFixture();
        $oldest = $this->account('oldest@capcut.com', 23);
        $this->account('fresh@capcut.com', 0);

        $first = app(OrderService::class)->place([
            'product_item_id' => $item->id,
            'payment_channel' => 'QRIS',
            'customer_phone' => '081234567890',
            'customer_data' => ['email' => 'a@test.com'],
        ]);

        $second = app(OrderService::class)->place([
            'product_item_id' => $item->id,
            'payment_channel' => 'QRIS',
            'customer_phone' => '081234567891',
            'customer_data' => ['email' => 'b@test.com'],
        ]);

        $this->assertNotSame($first->id, $second->id);
        $this->assertSame('10000.00', (string) $first->fresh()->amount);
        $this->assertSame('25000.00', (string) $second->fresh()->amount);

        $this->assertSame('reserved', $oldest->fresh()->status);
        $this->assertSame($first->id, $oldest->fresh()->transaction_id);
        $this->assertSame('reserved', CapcutAccount::where('email', 'fresh@capcut.com')->first()->status);
    }

    public function test_expired_invoice_releases_reserved_account_back_to_stock(): void
    {
        [, $item] = $this->capcutFixture();
        $account = $this->account('release@capcut.com', 10);

        $transaction = app(OrderService::class)->place([
            'product_item_id' => $item->id,
            'payment_channel' => 'QRIS',
            'customer_phone' => '081234567890',
            'customer_data' => ['email' => 'buyer@test.com'],
        ]);

        $this->assertSame('reserved', $account->fresh()->status);

        // Jalur yang dipakai InvoiceController/Webhook saat invoice kedaluwarsa.
        $transaction->forceFill(['status' => 'expired'])->save();
        CapcutAccount::releaseForTransaction($transaction->id);
        $account->refresh();

        $this->assertSame('ready', $account->status);
        $this->assertNull($account->transaction_id);
        $this->assertSame(20000, $account->tierInfo()['price']);
        $this->assertSame(1, CapcutAccount::query()->sellable()->count());
    }

    public function test_checkout_without_sellable_stock_is_rejected(): void
    {
        [, $item] = $this->capcutFixture();
        $this->account('expired@capcut.com', 31);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('sedang habis');

        app(OrderService::class)->place([
            'product_item_id' => $item->id,
            'payment_channel' => 'QRIS',
            'customer_phone' => '081234567890',
            'customer_data' => ['email' => 'buyer@test.com'],
        ]);
    }

    public function test_price_catalog_uses_config_overrides(): void
    {
        config(['services.capcut.tiers.clearance.price' => 9000]);

        $this->assertSame(9000, CapcutTierService::price('clearance'));
        $this->assertSame(9000, $this->account('cfg@capcut.com', 24)->tierInfo()['price']);
    }

    /** Transaksi CapCut siap dibayar tanpa lewat gateway (untuk skenario bayar telat). */
    private function paidCapcutTransaction(ProductItem $item, string $invoice, int $amount): Transaction
    {
        return Transaction::create([
            'invoice_id' => $invoice,
            'product_item_id' => $item->id,
            'customer_data' => ['email' => 'buyer@test.com'],
            'customer_phone' => '081234567890',
            'customer_email' => 'buyer@test.com',
            'amount' => $amount,
            'total' => $amount,
            'payment_gateway' => 'belibayar',
            'status' => 'paid',
            'paid_at' => Carbon::now(),
        ]);
    }

    public function test_scheduled_expiry_releases_only_accounts_of_the_expired_transactions(): void
    {
        [, $item] = $this->capcutFixture();
        $expiredAccount = $this->account('leak@capcut.com', 10);
        $paidAccount = $this->account('paid@capcut.com', 10);

        $expiredTrx = app(OrderService::class)->place([
            'product_item_id' => $item->id,
            'payment_channel' => 'QRIS',
            'customer_phone' => '081234567890',
            'customer_data' => ['email' => 'late@test.com'],
        ]);
        $paidTrx = app(OrderService::class)->place([
            'product_item_id' => $item->id,
            'payment_channel' => 'QRIS',
            'customer_phone' => '081234567891',
            'customer_data' => ['email' => 'paid@test.com'],
        ]);

        $expiredTrx->forceFill(['expired_at' => Carbon::now()->subMinute()])->save();
        $paidTrx->forceFill([
            'status' => 'paid',
            'paid_at' => Carbon::now(),
            'expired_at' => Carbon::now()->subMinute(),
        ])->save();

        $this->artisan('orders:expire')->assertExitCode(0);

        $this->assertSame('expired', $expiredTrx->fresh()->status);
        $expiredAccount->refresh();
        $this->assertSame('ready', $expiredAccount->status);
        $this->assertNull($expiredAccount->transaction_id);
        $this->assertNull($expiredAccount->channel);

        // Transaksi yang sudah paid tidak boleh dilepas reservasinya.
        $this->assertSame('paid', $paidTrx->fresh()->status);
        $paidAccount->refresh();
        $this->assertSame('reserved', $paidAccount->status);
        $this->assertSame($paidTrx->id, $paidAccount->transaction_id);
    }

    public function test_payment_reconcile_expiry_releases_the_reserved_account(): void
    {
        [, $item] = $this->capcutFixture();
        $account = $this->account('reconcile@capcut.com', 10);

        $trx = app(OrderService::class)->place([
            'product_item_id' => $item->id,
            'payment_channel' => 'QRIS',
            'customer_phone' => '081234567890',
            'customer_data' => ['email' => 'reconcile@test.com'],
        ]);

        $trx->forceFill([
            'payment_ref' => 'byrtrx_reconcile',
            'created_at' => Carbon::now()->subMinutes(5),
            'expired_at' => Carbon::now()->subMinute(),
        ])->save();

        $client = \Mockery::mock(\App\Services\Payment\BelibayarClient::class);
        $client->shouldReceive('status')->once()->andReturn(['status' => 'expired']);
        $this->app->instance(\App\Services\Payment\BelibayarClient::class, $client);

        $this->artisan('payments:reconcile')->assertExitCode(0);

        $this->assertSame('expired', $trx->fresh()->status);
        $account->refresh();
        $this->assertSame('ready', $account->status);
        $this->assertNull($account->transaction_id);
    }

    public function test_late_payment_never_receives_account_below_the_paid_amount(): void
    {
        [, $item] = $this->capcutFixture();
        $cheap = $this->account('clearance@capcut.com', 24); // Rp 10.000

        $trx = $this->paidCapcutTransaction($item, 'INV-CAPCUT-LATE-1', 25000);

        $fulfilled = app(FulfillmentService::class)->fulfill($trx);

        $this->assertSame('processing', $fulfilled->status);
        $this->assertStringContainsString('tidak tersedia', (string) $fulfilled->supplier_response);
        $this->assertStringContainsString('25.000', (string) $fulfilled->supplier_response);
        $this->assertNull($fulfilled->supplier_sn);

        $cheap->refresh();
        $this->assertSame('ready', $cheap->status);
        $this->assertNull($cheap->transaction_id);
    }

    public function test_late_payment_fallback_uses_stock_that_matches_the_paid_tier(): void
    {
        [, $item] = $this->capcutFixture();
        $cheap = $this->account('clearance@capcut.com', 24); // Rp 10.000
        $eligible = $this->account('eligible@capcut.com', 10); // Rp 20.000

        $trx = $this->paidCapcutTransaction($item, 'INV-CAPCUT-LATE-2', 20000);

        $fulfilled = app(FulfillmentService::class)->fulfill($trx);

        $this->assertSame('completed', $fulfilled->status);
        $this->assertStringContainsString('eligible@capcut.com', (string) $fulfilled->supplier_sn);

        $eligible->refresh();
        $this->assertSame('sold', $eligible->status);
        $this->assertSame($trx->id, $eligible->transaction_id);

        $cheap->refresh();
        $this->assertSame('ready', $cheap->status);
    }

    public function test_expired_reserved_account_retries_eligible_ready_stock_before_processing(): void
    {
        [, $item] = $this->capcutFixture();
        $stale = $this->account('stale@capcut.com', 31, 'reserved');
        $ready = $this->account('ready@capcut.com', 10); // Rp 20.000

        $trx = $this->paidCapcutTransaction($item, 'INV-CAPCUT-STALE-1', 20000);
        $stale->forceFill(['transaction_id' => $trx->id, 'channel' => 'web'])->save();

        $fulfilled = app(FulfillmentService::class)->fulfill($trx);

        $this->assertSame('completed', $fulfilled->status);
        $this->assertStringContainsString('ready@capcut.com', (string) $fulfilled->supplier_sn);

        $this->assertSame('expired', $stale->fresh()->status);
        $ready->refresh();
        $this->assertSame('sold', $ready->status);
        $this->assertSame($trx->id, $ready->transaction_id);
    }
}