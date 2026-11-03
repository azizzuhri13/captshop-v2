<?php

namespace Tests\Feature\Order;

use App\Models\Category;
use App\Models\PaymentChannel;
use App\Models\Product;
use App\Models\ProductItem;
use App\Models\Transaction;
use App\Services\OrderService;
use App\Services\Payment\TripayClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\TestCase;

/**
 * Integrasi checkout Tripay: satu tes create end-to-end (client + normalisasi
 * + penyimpanan instruksi), satu tes inquiry mismatch pada halaman invoice,
 * dan satu tes render instruksi VA. Callback/webhook tidak disentuh di sini.
 */
class TripayCheckoutTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();

        config()->set('payment.default', 'tripay');
        config()->set('payment.tripay.enabled', true);
        config()->set('payment.tripay.mode', 'sandbox');
        config()->set('payment.tripay.api_key', 'dev-api-key');
        config()->set('payment.tripay.private_key', 'dev-private-key');
        config()->set('payment.tripay.merchant_code', 'T12345');
        config()->set('payment.tripay.base_url', null);
    }

    /** Checkout DigiFlazz butuh pricelist segar; fixture ini yang menyediakannya. */
    private function fakeFreshCatalog(string $sku): void
    {
        $path = storage_path('framework/testing/digiflazz-'.uniqid().'.json');
        config(['supplier.digiflazz.pricelist.cache_path' => $path]);
        @mkdir(dirname($path), 0775, true);
        file_put_contents($path, json_encode([
            'version' => 1,
            'fetched_at' => now()->toIso8601String(),
            'count' => 1,
            'items' => [[
                'buyer_sku_code' => $sku,
                'price' => 10000,
                'buyer_product_status' => true,
                'seller_product_status' => true,
                'seller_name' => 'GAMEPEDIA',
            ]],
        ]));
    }

    private function itemWithChannel(string $channelCode, string $group): ProductItem
    {
        PaymentChannel::create([
            'gateway' => 'tripay', 'code' => $channelCode, 'name' => $channelCode,
            'group' => $group, 'flat_fee' => '0', 'percent_fee' => '0', 'is_active' => true,
        ]);
        $category = Category::create(['name' => 'Games', 'slug' => 'games']);
        $product = Product::create([
            'category_id' => $category->id, 'name' => 'Voucher', 'slug' => 'voucher',
            'supplier_type' => 'digiflazz',
        ]);

        return ProductItem::create([
            'product_id' => $product->id, 'name' => '10 gems', 'supplier_item_code' => 'ml-10',
            'buy_price' => '10000', 'sell_price' => '15000', 'is_active' => true,
        ]);
    }

    private function tripayTransaction(array $overrides = []): Transaction
    {
        $category = Category::create(['name' => 'Games', 'slug' => 'games']);
        $product = Product::create(['category_id' => $category->id, 'name' => 'Game', 'slug' => 'game']);
        $item = ProductItem::create([
            'product_id' => $product->id, 'name' => 'Voucher',
            'buy_price' => '10000', 'sell_price' => '15000',
        ]);

        return Transaction::create(array_merge([
            'invoice_id' => 'INV-TRIPAY-1', 'product_item_id' => $item->id,
            'customer_data' => ['user_id' => '123'], 'customer_phone' => '081234567890',
            'customer_email' => 'buyer@example.com',
            'amount' => '15000', 'fee' => '0', 'total' => '15000', 'profit' => '5000',
            'payment_gateway' => 'tripay', 'payment_channel' => 'BNI',
            'payment_ref' => 'T-PROVIDER-1', 'status' => 'pending',
            'expired_at' => now()->addHour(),
        ], $overrides));
    }

    public function test_tripay_checkout_saves_canonical_instructions_and_payable_amount(): void
    {
        $item = $this->itemWithChannel('BCA', 'Virtual Account');
        $this->fakeFreshCatalog('ml-10');

        Http::fake(function ($request) {
            $body = json_decode($request->body(), true);
            $this->assertSame('BCAVA', $body['method']);
            $this->assertSame('ml-10', $body['order_items'][0]['sku']);

            return Http::response(['success' => true, 'data' => [
                'reference' => 'T1234567890',
                'merchant_ref' => $body['merchant_ref'],
                'payment_method' => 'BCAVA',
                'amount' => $body['amount'],
                'fee_customer' => 700,
                'status' => 'UNPAID',
                'expired_time' => now()->addHour()->timestamp,
                'pay_code' => '8808123456789012',
                'checkout_url' => 'https://tripay.co.id/checkout/T1234567890',
            ]], 201);
        });

        $transaction = app(OrderService::class)->place([
            'product_item_id' => $item->id, 'payment_channel' => 'BCA',
            'customer_data' => ['user_id' => '123'], 'customer_phone' => '081234567890',
            'customer_email' => 'buyer@example.com',
        ]);

        Http::assertSentCount(1);

        $this->assertSame('tripay', $transaction->payment_gateway);
        $this->assertSame('T1234567890', $transaction->payment_ref);
        $this->assertSame('BCAVA', $transaction->payment_instructions['channel']);
        $this->assertSame('8808123456789012', $transaction->payment_instructions['va_number']);
        $this->assertSame(
            (int) round((float) $transaction->total) + 700,
            $transaction->payment_instructions['payable_amount'],
        );
        $this->assertSame('https://tripay.co.id/checkout/T1234567890', $transaction->payment_url);
        $this->assertNotNull($transaction->expired_at);
    }

    public function test_tripay_inquiry_with_mismatched_provider_reference_never_marks_paid(): void
    {
        $transaction = $this->tripayTransaction();

        $client = Mockery::mock(TripayClient::class);
        $client->shouldReceive('transactionDetail')->once()->with('T-PROVIDER-1')->andReturn([
            'reference' => 'T-OTHER-REFERENCE',
            'merchant_ref' => $transaction->invoice_id,
            'payment_method' => 'BNIVA',
            'amount' => 15000,
            'fee_customer' => 0,
            'status' => 'PAID',
            'expired_time' => now()->addMinutes(30)->timestamp,
        ]);
        $this->app->instance(TripayClient::class, $client);

        $this->post(route('invoice.check-status', $transaction->invoice_id))->assertRedirect();

        $transaction->refresh();
        $this->assertSame('pending', $transaction->status);
        $this->assertNull($transaction->paid_at);
    }

    public function test_tripay_invoice_renders_va_instruction_and_customer_fee_note(): void
    {
        $transaction = $this->tripayTransaction([
            'payment_instructions' => [
                'merchant_ref' => 'INV-TRIPAY-1',
                'channel' => 'BNIVA',
                'va_number' => '8808123456789012',
                'qr_content' => null,
                'qr_url' => null,
                'payable_amount' => 15700,
            ],
        ]);

        $this->get(route('invoice.show', $transaction->invoice_id))
            ->assertOk()
            ->assertSee('Nomor Virtual Account')
            ->assertSee('8808123456789012')
            ->assertSee('15.700');
    }
}