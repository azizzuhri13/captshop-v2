<?php

namespace Tests\Feature\Order;

use App\Models\Category;
use App\Models\NetflixAccount;
use App\Models\Product;
use App\Models\ProductItem;
use App\Models\Transaction;
use App\Services\FulfillmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NetflixFulfillmentTest extends TestCase
{
    use RefreshDatabase;

    public function test_netflix_transaction_is_fulfilled_from_internal_stock(): void
    {
        $category = Category::firstOrCreate(['slug' => 'aplikasi'], ['name' => 'Aplikasi', 'is_active' => true]);
        $product = Product::firstOrCreate(['slug' => 'netflix-premium'], [
            'category_id' => $category->id,
            'name' => 'Netflix Premium',
            'supplier_type' => 'manual',
            'is_active' => true,
        ]);
        $item = ProductItem::firstOrCreate(['name' => '1P1U 1 Bulan', 'product_id' => $product->id], [
            'buy_price' => 25000,
            'sell_price' => 30000,
            'is_active' => true,
        ]);

        $account = NetflixAccount::create([
            'email' => 'test.stock@captshop.id',
            'password' => 'SecretPass123',
            'profile_name' => 'PROFIL 2',
            'pin' => '9999',
            'status' => 'ready',
        ]);

        $trx = Transaction::create([
            'invoice_id' => strtoupper('INV-TEST-NTFX-' . uniqid()),
            'product_item_id' => $item->id,
            'customer_data' => ['whatsapp' => '081234567890'],
            'customer_phone' => '081234567890',
            'customer_email' => 'buyer@test.com',
            'amount' => 30000,
            'total' => 30000,
            'status' => 'paid',
        ]);

        $service = app(FulfillmentService::class);
        $fulfilled = $service->fulfill($trx);

        $this->assertEquals('completed', $fulfilled->status);
        $this->assertNotNull($fulfilled->completed_at);
        $this->assertStringContainsString('test.stock@captshop.id', $fulfilled->supplier_sn);

        $account->refresh();
        $this->assertEquals('sold', $account->status);
        $this->assertEquals($trx->id, $account->transaction_id);

        // Test invoice view publik (tanpa signature): kredensial terkunci
        $publicResponse = $this->get(route('invoice.show', $trx->invoice_id));
        $publicResponse->assertStatus(200);
        $publicResponse->assertSee('Kredensial Akun Dikunci');
        $publicResponse->assertDontSee('SecretPass123');

        // Test invoice view via tautan resmi bertanda tangan (signed URL)
        $signedUrl = \Illuminate\Support\Facades\URL::signedRoute('invoice.show', ['invoiceId' => $trx->invoice_id]);
        $signedResponse = $this->get($signedUrl);
        $signedResponse->assertStatus(200);
        $signedResponse->assertSee('test.stock@captshop.id');
        $signedResponse->assertSee('SecretPass123');
        $signedResponse->assertSee('PROFIL 2');
        $signedResponse->assertSee('9999');
        $signedResponse->assertSee('Buka Netflix');
    }

    public function test_invoice_shows_whatsapp_button_when_netflix_stock_empty(): void
    {
        $category = Category::firstOrCreate(['slug' => 'aplikasi'], ['name' => 'Aplikasi', 'is_active' => true]);
        $product = Product::firstOrCreate(['slug' => 'netflix-premium'], [
            'category_id' => $category->id,
            'name' => 'Netflix Premium',
            'supplier_type' => 'manual',
            'is_active' => true,
        ]);
        $item = ProductItem::firstOrCreate(['name' => '1P1U 1 Bulan', 'product_id' => $product->id], [
            'buy_price' => 0,
            'sell_price' => 30000,
            'is_active' => true,
        ]);

        $trx = Transaction::create([
            'invoice_id' => strtoupper('INV-EMPTY-NTFX-' . uniqid()),
            'product_item_id' => $item->id,
            'customer_data' => ['whatsapp' => '081234567890'],
            'customer_phone' => '081234567890',
            'customer_email' => 'buyer@test.com',
            'amount' => 30000,
            'total' => 30000,
            'status' => 'processing',
        ]);

        $response = $this->get(route('invoice.show', $trx->invoice_id));
        $response->assertStatus(200);
        $response->assertSee('Pembayaran Diterima — Menunggu Stok Akun');
        $response->assertSee('Hubungi WhatsApp Admin');
        $response->assertDontSee('Hubungi WhatsApp Admin (+62');
        $response->assertSee('https://wa.me/6282260677717?text=', false);
        $response->assertSee($trx->invoice_id);
    }
}
