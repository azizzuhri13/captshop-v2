<?php

namespace Tests\Feature\Order;

use App\Models\Category;
use App\Models\NetflixAccount;
use App\Models\Product;
use App\Models\ProductItem;
use App\Models\Transaction;
use App\Services\FulfillmentService;
use App\Services\Supplier\SupplierInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class ManualProductFulfillmentTest extends TestCase
{
    use RefreshDatabase;

    public function test_manual_product_does_not_consume_netflix_account(): void
    {
        $category = Category::create(['name' => 'Aplikasi', 'slug' => 'aplikasi']);
        $product = Product::create([
            'category_id' => $category->id,
            'name' => 'CapCut Pro',
            'slug' => 'capcut',
            'supplier_type' => 'manual',
            'is_active' => true,
        ]);
        $item = ProductItem::create([
            'product_id' => $product->id,
            'name' => '1 Bulan',
            'buy_price' => 10000,
            'sell_price' => 15000,
            'is_active' => true,
        ]);

        $netflixAccount = NetflixAccount::create([
            'email' => 'keep.safe@captshop.id',
            'password' => 'SafePass123',
            'status' => 'ready',
        ]);

        $trx = Transaction::create([
            'invoice_id' => 'INV-MANUAL-TEST1',
            'product_item_id' => $item->id,
            'customer_data' => ['whatsapp' => '081234567890'],
            'customer_phone' => '081234567890',
            'amount' => 15000,
            'total' => 15000,
            'status' => 'paid',
        ]);

        $service = app(FulfillmentService::class);
        $result = $service->fulfill($trx);

        $this->assertEquals('processing', $result->status);
        $this->assertEquals('MANUAL-INV-MANUAL-TEST1', $result->supplier_ref);

        // Pastikan akun Netflix tetap ready dan tidak terjual
        $netflixAccount->refresh();
        $this->assertEquals('ready', $netflixAccount->status);
        $this->assertNull($netflixAccount->transaction_id);
    }

    public function test_orders_reconcile_ignores_manual_products(): void
    {
        $category = Category::create(['name' => 'Aplikasi', 'slug' => 'aplikasi']);
        $product = Product::create([
            'category_id' => $category->id,
            'name' => 'Viu Premium',
            'slug' => 'viu-premium',
            'supplier_type' => 'manual',
            'is_active' => true,
        ]);
        $item = ProductItem::create([
            'product_id' => $product->id,
            'name' => '1 Bulan',
            'buy_price' => 10000,
            'sell_price' => 15000,
            'is_active' => true,
        ]);

        $trx = Transaction::create([
            'invoice_id' => 'INV-RECON-MANUAL',
            'product_item_id' => $item->id,
            'customer_data' => ['whatsapp' => '081234567890'],
            'customer_phone' => '081234567890',
            'amount' => 15000,
            'total' => 15000,
            'status' => 'processing',
            'created_at' => now()->subMinutes(10),
        ]);

        // Mock SupplierInterface agar kita bisa pastikan topup() TIDAK dipanggil untuk produk manual
        $mockSupplier = $this->mock(SupplierInterface::class);
        $mockSupplier->shouldNotReceive('topup');

        Artisan::call('orders:reconcile');

        $trx->refresh();
        $this->assertEquals('processing', $trx->status);
    }
}
