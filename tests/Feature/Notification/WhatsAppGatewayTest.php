<?php

namespace Tests\Feature\Notification;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductItem;
use App\Models\Transaction;
use App\Services\Notification\WhatsAppService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class WhatsAppGatewayTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Config::set('services.whatsapp_gateway.url', 'http://127.0.0.1:3211');
        Config::set('services.whatsapp_gateway.key', 'unit-test-key');
    }

    private function transaction(array $overrides = []): Transaction
    {
        $category = Category::create(['name' => 'Test', 'slug' => 'test']);
        $product = Product::create(['category_id' => $category->id, 'name' => 'Product', 'slug' => 'product']);
        $item = ProductItem::create([
            'product_id' => $product->id,
            'name' => 'Product item',
            'buy_price' => '1000',
            'sell_price' => '1500',
        ]);

        return Transaction::create(array_merge([
            'invoice_id' => 'INV-GATEWAY-TEST',
            'product_item_id' => $item->id,
            'customer_data' => ['user_id' => '123'],
            'customer_phone' => '0812 3456 7890',
            'amount' => '1500',
            'total' => '1500',
            'status' => 'completed',
            'supplier_sn' => 'SECRET-SUPPLIER-SN',
        ], $overrides));
    }

    public function test_completed_message_uses_gateway_idempotency_and_excludes_supplier_serial(): void
    {
        Http::fake(['127.0.0.1:3211/*' => Http::response(['status' => 'queued'], 202)]);
        $transaction = $this->transaction();

        $this->assertTrue(app(WhatsAppService::class)->sendSuccessNotification($transaction));

        Http::assertSent(function ($request) use ($transaction) {
            return $request->url() === 'http://127.0.0.1:3211/v1/messages/text'
                && $request->header('X-Internal-Key')[0] === 'unit-test-key'
                && $request->header('Idempotency-Key')[0] === 'transaction:'.$transaction->invoice_id.':event:completed'
                && $request['to'] === '6281234567890'
                && $request['metadata']['invoice_id'] === $transaction->invoice_id
                && str_contains($request['text'], 'Detail pesanan:')
                && ! str_contains($request['text'], 'SECRET-SUPPLIER-SN')
                && ! str_contains($request['text'], 'kredensial');
        });
    }

    public function test_invalid_phone_is_rejected_without_gateway_request(): void
    {
        Http::fake();
        $transaction = $this->transaction(['customer_phone' => 'abc']);

        $this->assertFalse(app(WhatsAppService::class)->sendInvoiceNotification($transaction));
        Http::assertNothingSent();
    }

    public function test_gateway_failure_returns_false_without_throwing(): void
    {
        Http::fake(['127.0.0.1:3211/*' => Http::response([], 503)]);
        $transaction = $this->transaction();

        $this->assertFalse(app(WhatsAppService::class)->sendPaidNotification($transaction));
    }

    public function test_loopback_gateway_urls_are_accepted(): void
    {
        Log::spy();
        $transaction = $this->transaction();
        $cases = [
            'http://127.0.0.1:3211' => 'http://127.0.0.1:3211/v1/messages/text',
            'http://localhost:3211/' => 'http://localhost:3211/v1/messages/text',
            'http://[::1]:3211' => 'http://[::1]:3211/v1/messages/text',
        ];

        foreach ($cases as $configured => $expected) {
            Config::set('services.whatsapp_gateway.url', $configured);
            Http::fake(['*' => Http::response(['status' => 'queued'], 202)]);

            $this->assertTrue(
                app(WhatsAppService::class)->sendSuccessNotification($transaction),
                "Loopback URL should be accepted: {$configured}"
            );

            Http::assertSent(fn ($request) => $request->url() === $expected
                && $request->header('X-Internal-Key')[0] === 'unit-test-key');
        }

        Log::shouldNotHaveReceived('warning');
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function rejectedGatewayUrlProvider(): array
    {
        return [
            'https loopback' => ['https://127.0.0.1:3211'],
            'foreign host' => ['http://evil.example.com:3211'],
            'lookalike host' => ['http://127.0.0.1.evil.example.com:3211'],
            'credentials' => ['http://user:pass@127.0.0.1:3211'],
            'path prefix' => ['http://127.0.0.1:3211/v1'],
            'query string' => ['http://127.0.0.1:3211?token=leak'],
            'fragment' => ['http://127.0.0.1:3211#fragment'],
            'out of range port' => ['http://localhost:70000'],
            'malformed' => ['not-a-url'],
            'empty' => [''],
            'newline host confusion' => ["http://127.0.0.1:3211\n@evil.example.com"],
        ];
    }

    /**
     * @dataProvider rejectedGatewayUrlProvider
     */
    public function test_non_loopback_gateway_url_is_rejected_without_request_or_secret_log(string $configured): void
    {
        Config::set('services.whatsapp_gateway.url', $configured);
        Http::fake();
        Log::spy();
        $transaction = $this->transaction();

        $this->assertFalse(app(WhatsAppService::class)->sendSuccessNotification($transaction));

        Http::assertNothingSent();
        Log::shouldNotHaveReceived('warning', fn ($message) => is_string($message)
            && (str_contains($message, 'unit-test-key') || str_contains($message, 'evil')));
        Log::shouldHaveReceived('warning')
            ->with('Notifikasi WhatsApp dilewati: konfigurasi atau nomor tujuan tidak valid.')
            ->once();
    }
}
