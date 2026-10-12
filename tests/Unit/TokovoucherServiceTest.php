<?php

namespace Tests\Unit;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductItem;
use App\Models\Transaction;
use App\Services\Supplier\TokovoucherClient;
use App\Services\Supplier\TokovoucherService;
use Mockery;
use Tests\TestCase;

class TokovoucherServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config()->set('supplier.tokovoucher.member_code', 'MEM123');
        config()->set('supplier.tokovoucher.secret_key', 'SEC456');
    }

    public function test_topup_sends_correct_signature_and_normalizes_response(): void
    {
        $category = new Category(['slug' => 'games']);
        $product = new Product(['supplier_code' => 'free-fire', 'supplier_type' => 'tokovoucher']);
        $product->setRelation('category', $category);
        $item = new ProductItem(['supplier_item_code' => 'FF50']);
        $item->setRelation('product', $product);

        $transaction = new Transaction([
            'invoice_id' => 'INV-TEST-001',
            'customer_phone' => '081234567890',
        ]);
        $transaction->setRelation('productItem', $item);

        $client = Mockery::mock(TokovoucherClient::class);
        $client->shouldReceive('post')
            ->once()
            ->withArgs(function ($endpoint, $payload) {
                return $endpoint === '/v1/transaksi'
                    && $payload['ref_id'] === 'INV-TEST-001'
                    && $payload['produk'] === 'FF50'
                    && $payload['tujuan'] === '12345678'
                    && $payload['server_id'] === '2001'
                    && $payload['member_code'] === 'MEM123'
                    && $payload['signature'] === md5('MEM123:SEC456:INV-TEST-001');
            })
            ->andReturn([
                'status' => 'sukses',
                'sn' => 'SN98765',
                'trx_id' => 'TRX777',
                'message' => 'Transaksi Sukses',
            ]);

        $service = new TokovoucherService($client);
        $result = $service->topup($transaction, [
            'user_id' => '12345678',
            'zone_id' => '2001',
        ]);

        $this->assertSame('completed', $result['status']);
        $this->assertSame('TRX777', $result['supplier_ref']);
        $this->assertSame('SN98765', $result['supplier_sn']);
        $this->assertSame('Transaksi Sukses', $result['message']);
    }

    public function test_check_balance_returns_numeric_balance(): void
    {
        $client = Mockery::mock(TokovoucherClient::class);
        $client->shouldReceive('get')
            ->once()
            ->withArgs(function ($endpoint, $query) {
                return $endpoint === '/member'
                    && $query['member_code'] === 'MEM123'
                    && $query['signature'] === md5('MEM123:SEC456');
            })
            ->andReturn([
                'status' => 1,
                'rc' => 200,
                'data' => [
                    'saldo' => 150000,
                    'nama' => 'Merchant Test',
                ],
            ]);

        $service = new TokovoucherService($client);
        $res = $service->checkBalance();

        $this->assertSame(150000.0, $res['balance']);
    }
}
