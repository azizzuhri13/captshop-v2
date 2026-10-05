<?php

namespace Tests\Feature\Frontend;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductItem;
use App\Models\Transaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TrackAndPagesTest extends TestCase
{
    use RefreshDatabase;

    protected function createTransaction(string $invoiceId = 'INV-20260908-TRACK1'): Transaction
    {
        $category = Category::create(['name' => 'Games', 'slug' => 'games']);
        $product = Product::create(['category_id' => $category->id, 'name' => 'Mobile Legends', 'slug' => 'mobile-legends']);
        $item = ProductItem::create(['product_id' => $product->id, 'name' => '86 Diamonds', 'sell_price' => '15000']);

        return Transaction::create([
            'invoice_id' => $invoiceId,
            'product_item_id' => $item->id,
            'customer_data' => ['user_id' => '12345'],
            'customer_phone' => '081234567890',
            'amount' => '15000',
            'fee' => '0',
            'total' => '15000',
            'profit' => '0',
            'status' => 'pending',
        ]);
    }

    public function test_track_form_renders(): void
    {
        $this->get('/cek-transaksi')->assertStatus(200)->assertSee('Cek Transaksi');
    }

    public function test_track_by_invoice_id(): void
    {
        $transaction = $this->createTransaction();

        $response = $this->post('/cek-transaksi', ['q' => $transaction->invoice_id]);

        $response->assertStatus(200);
        $response->assertSee($transaction->invoice_id);
    }

    public function test_track_by_phone_number(): void
    {
        $transaction = $this->createTransaction();

        $response = $this->post('/cek-transaksi', ['q' => '081234567890']);

        $response->assertStatus(200);
        $response->assertSee($transaction->invoice_id);
    }

    public function test_track_no_result(): void
    {
        $response = $this->post('/cek-transaksi', ['q' => 'INV-NOT-FOUND-XYZ']);

        $response->assertStatus(200);
        $response->assertSee('Tidak ada transaksi ditemukan');
    }

    public function test_static_pages_render(): void
    {
        $this->get('/tentang-kami')->assertStatus(200)->assertSee('Tentang');
        $this->get('/faq')->assertStatus(200)->assertSee('Pusat Bantuan');
        $this->get('/syarat-ketentuan')->assertStatus(200)->assertSee('Syarat & Ketentuan');
        $this->get('/kebijakan-privasi')->assertStatus(200)->assertSee('Kebijakan Privasi');
    }
}
