<?php

namespace Tests\Feature\Order;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductItem;
use App\Models\SiteSetting;
use App\Models\Transaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Halaman invoice untuk produk manual non-streaming (mis. Roblox manual) harus
 * menampilkan tombol "Hubungi WhatsApp Admin" dengan URL wa.me memakai nomor CS
 * dari site_settings, dan teks pesan berisi invoice, produk, total, dan data
 * pembeli. Kartu ini tidak boleh muncul untuk produk streaming (yang punya kartu
 * amber tersendiri), tidak boleh muncul saat pending, dan tidak boleh menghardcode
 * nomor admin.
 */
class ManualInvoiceWhatsAppButtonTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();

        // Nomor CS tersimpan di site_settings (bukan hardcode di view).
        SiteSetting::updateOrCreate(['key' => 'contact_whatsapp'], ['value' => '08123456789']);
    }

    private function makeManualProduct(string $slug, string $supplierType = 'manual'): array
    {
        $category = Category::firstOrCreate(
            ['slug' => 'games'],
            ['name' => 'Games', 'is_active' => true]
        );

        $product = Product::create([
            'category_id' => $category->id,
            'name' => 'Roblox',
            'slug' => $slug,
            'supplier_type' => $supplierType,
            'is_active' => true,
        ]);

        $item = ProductItem::create([
            'product_id' => $product->id,
            'name' => '400 Robux',
            'buy_price' => 70000,
            'sell_price' => 79000,
            'is_active' => true,
        ]);

        return [$product, $item];
    }

    private function makeTransaction(ProductItem $item, string $status = 'processing'): Transaction
    {
        return Transaction::create([
            'invoice_id' => strtoupper('INV-RBX-'.uniqid()),
            'product_item_id' => $item->id,
            'customer_data' => ['username' => 'winterpriya'],
            'customer_phone' => '081234567890',
            'customer_email' => 'buyer@test.com',
            'amount' => 79000,
            'total' => 79000,
            'status' => $status,
        ]);
    }

    public function test_manual_product_invoice_shows_wa_button_with_cs_number(): void
    {
        [, $item] = $this->makeManualProduct('roblox-manual');
        $trx = $this->makeTransaction($item, 'processing');

        $response = $this->get(route('invoice.show', $trx->invoice_id));

        $response->assertStatus(200);
        $response->assertSee('Lunas! Lanjut ke WhatsApp Admin');
        $response->assertSee('Hubungi WhatsApp Admin');
        // Nomor berasal dari site_settings, dinormalkan ke 62 prefix — bukan hardcode.
        $response->assertSee('https://wa.me/628123456789?text=', false);
        $response->assertSee($trx->invoice_id);
        $response->assertSee('winterpriya');
        $response->assertSee('Rp 79.000');
    }

    public function test_manual_invoice_wa_url_contains_invoice_product_total_and_customer_data(): void
    {
        [, $item] = $this->makeManualProduct('roblox-manual');
        $trx = $this->makeTransaction($item, 'processing');

        $response = $this->get(route('invoice.show', $trx->invoice_id));

        $response->assertStatus(200);

        // Cari URL wa.me di HTML lalu dekode parameter text.
        $html = $response->getContent() ?? '';
        $this->assertMatchesRegularExpression(
            '#https://wa\.me/628123456789\?text=([^"\']+)#',
            $html,
            'Expected a wa.me URL with a text parameter built from site settings.'
        );

        preg_match('#https://wa\.me/628123456789\?text=([^"\']+)#', $html, $m);
        $waText = rawurldecode($m[1]);

        $this->assertStringContainsString($trx->invoice_id, $waText);
        $this->assertStringContainsString('Roblox', $waText);
        $this->assertStringContainsString('400 Robux', $waText);
        $this->assertStringContainsString('79.000', $waText);
        $this->assertStringContainsString('winterpriya', $waText);
    }

    public function test_manual_invoice_hides_wa_card_for_pending_and_completed_statuses(): void
    {
        [, $item] = $this->makeManualProduct('roblox-manual');

        $pending = $this->makeTransaction($item, 'pending');
        $this->get(route('invoice.show', $pending->invoice_id))
            ->assertStatus(200)
            ->assertDontSee('Hubungi WhatsApp Admin');

        $completed = $this->makeTransaction($item, 'completed');
        $this->get(route('invoice.show', $completed->invoice_id))
            ->assertStatus(200)
            ->assertDontSee('Hubungi WhatsApp Admin');
    }

    public function test_streaming_manual_product_uses_existing_card_not_manual_wa_card(): void
    {
        // Netflix (manual + streaming) harus tetap memakai kartu amber existing,
        // bukan kartu manual baru — mencegah dua kartu WA tampil bersamaan.
        $category = Category::firstOrCreate(['slug' => 'aplikasi'], ['name' => 'Aplikasi', 'is_active' => true]);
        $product = Product::firstOrCreate(['slug' => 'netflix-premium'], [
            'category_id' => $category->id,
            'name' => 'Netflix Premium',
            'supplier_type' => 'manual',
            'is_active' => true,
        ]);
        $item = ProductItem::create([
            'product_id' => $product->id,
            'name' => '1P1U 1 Bulan',
            'buy_price' => 0,
            'sell_price' => 30000,
            'is_active' => true,
        ]);
        $trx = Transaction::create([
            'invoice_id' => strtoupper('INV-NTFX-EMPTY-'.uniqid()),
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
        $response->assertSee('Hubungi WhatsApp Admin'); // kartu streaming amber
        $response->assertDontSee('Lunas! Lanjut ke WhatsApp Admin'); // kartu manual baru tidak tampil
    }
}
