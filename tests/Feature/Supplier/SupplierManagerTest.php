<?php

namespace Tests\Feature\Supplier;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductItem;
use App\Models\Transaction;
use App\Services\FulfillmentService;
use App\Services\Supplier\DigiflazzService;
use App\Services\Supplier\TokovoucherService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class SupplierManagerTest extends TestCase
{
    use RefreshDatabase;

    public function test_fulfillment_routes_to_tokovoucher_when_product_has_tokovoucher_supplier_type(): void
    {
        $category = Category::create(['name' => 'Games', 'slug' => 'games']);
        $product = Product::create([
            'category_id' => $category->id,
            'name' => 'Free Fire',
            'slug' => 'free-fire',
            'supplier_type' => 'tokovoucher',
            'supplier_code' => 'free-fire',
        ]);
        $item = ProductItem::create([
            'product_id' => $product->id,
            'name' => '50 Diamonds',
            'supplier_item_code' => 'FF50',
            'buy_price' => '6000',
            'sell_price' => '7000',
            'is_active' => true,
        ]);

        $transaction = Transaction::create([
            'invoice_id' => 'INV-FULFILL-TV',
            'product_item_id' => $item->id,
            'customer_data' => ['user_id' => '12345678'],
            'customer_phone' => '081234567890',
            'amount' => '7000',
            'total' => '7000',
            'status' => 'paid',
        ]);

        $tokovoucher = Mockery::mock(TokovoucherService::class);
        $tokovoucher->shouldReceive('topup')
            ->once()
            ->andReturn([
                'status' => 'completed',
                'supplier_ref' => 'TV-TRX-101',
                'supplier_sn' => 'SN-TOPUP-OK',
                'raw' => [],
            ]);
        $this->app->instance(TokovoucherService::class, $tokovoucher);

        $fulfillment = app(FulfillmentService::class);
        $result = $fulfillment->fulfill($transaction);

        $this->assertSame('completed', $result->status);
        $this->assertSame('TV-TRX-101', $result->supplier_ref);
        $this->assertSame('SN-TOPUP-OK', $result->supplier_sn);
    }
}
