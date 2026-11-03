<?php

namespace Tests\Feature\Payment;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductItem;
use App\Models\Transaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Permukaan pembayaran Tripay yang dipakai produksi: URL callback yang
 * didaftarkan di dashboard, endpoint webhook yang harus menjawab 403 (bukan
 * 404) untuk signature tidak sah, dan alur invoice pelanggan.
 */
class TripayPaymentFlowTest extends TestCase
{
    use RefreshDatabase;

    private const PRIVATE_KEY = 'tripay-private-test-key';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();

        config()->set('payment.tripay.enabled', true);
        config()->set('payment.tripay.mode', 'sandbox');
        config()->set('payment.tripay.api_key', 'dev-api-key');
        config()->set('payment.tripay.private_key', self::PRIVATE_KEY);
        config()->set('payment.tripay.merchant_code', 'T12345');
        config()->set('payment.tripay.base_url', null);
    }

    private function transaction(): Transaction
    {
        $category = Category::create(['name' => 'Games', 'slug' => 'games']);
        $product = Product::create(['category_id' => $category->id, 'name' => 'Game', 'slug' => 'game']);
        $item = ProductItem::create([
            'product_id' => $product->id, 'name' => 'Voucher',
            'buy_price' => '10000', 'sell_price' => '15000',
        ]);

        return Transaction::create([
            'invoice_id' => 'INV-TRIPAY-1',
            'product_item_id' => $item->id,
            'customer_data' => ['user_id' => '123'],
            'customer_phone' => '081234567890',
            'customer_email' => 'buyer@example.com',
            'amount' => '15000',
            'fee' => '0',
            'total' => '15000',
            'profit' => '5000',
            'payment_gateway' => 'tripay',
            'payment_channel' => 'BCA',
            'payment_ref' => 'T-PROVIDER-1',
            'status' => 'pending',
            'expired_at' => now()->addHour(),
            'payment_instructions' => [
                'merchant_ref' => 'INV-TRIPAY-1',
                'channel' => 'BCAVA',
                'va_number' => '8808123456789012',
                'qr_content' => null,
                'qr_url' => null,
                'payable_amount' => 15700,
            ],
        ]);
    }

    public function test_callback_url_is_registered_on_captshop_id_not_next_host(): void
    {
        $url = (string) config('payment.tripay.callback_url');

        $this->assertSame('https://captshop.id/webhook/tripay', $url);
        $this->assertSame('captshop.id', parse_url($url, PHP_URL_HOST));
        $this->assertSame('/webhook/tripay', parse_url($url, PHP_URL_PATH));
        $this->assertStringNotContainsString('next.', $url);
    }

    public function test_unsigned_webhook_post_returns_403_not_404(): void
    {
        $response = $this->postJson('/webhook/tripay', [
            'merchant_ref' => 'INV-TRIPAY-1',
            'status' => 'PAID',
        ]);

        // 403 membuktikan route ada dan menolak signature kosong; 404 berarti
        // dashboard Tripay menunjuk host/aplikasi yang salah.
        $response->assertStatus(403)->assertJson(['success' => false]);
        $this->assertNotSame(404, $response->getStatusCode());
    }

    public function test_invoice_page_and_check_status_work_for_tripay_transaction(): void
    {
        $transaction = $this->transaction();

        $this->get(route('invoice.show', $transaction->invoice_id))
            ->assertOk()
            ->assertSee('Nomor Virtual Account')
            ->assertSee('8808123456789012')
            ->assertSee('15.700');

        Http::fake([
            'tripay.co.id/api-sandbox/transaction/detail*' => Http::response([
                'success' => true,
                'data' => [
                    'reference' => 'T-PROVIDER-1',
                    'merchant_ref' => 'INV-TRIPAY-1',
                    'payment_method' => 'BCAVA',
                    'amount' => 15000,
                    'fee_customer' => 0,
                    'status' => 'UNPAID',
                    'expired_time' => now()->addHour()->timestamp,
                ],
            ], 200),
        ]);

        $this->post(route('invoice.check-status', $transaction->invoice_id))
            ->assertRedirect();

        Http::assertSentCount(1);
        $this->assertSame('pending', $transaction->fresh()->status);
    }
}