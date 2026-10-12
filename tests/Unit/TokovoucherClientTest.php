<?php

namespace Tests\Unit;

use App\Services\Supplier\SupplierException;
use App\Services\Supplier\TokovoucherClient;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TokovoucherClientTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config()->set('supplier.tokovoucher.member_code', 'MEM_TEST');
        config()->set('supplier.tokovoucher.secret_key', 'SECRET_TEST');
        config()->set('supplier.tokovoucher.base_url', 'https://api.tokovoucher.net');
    }

    public function test_post_sends_json_payload_and_returns_data(): void
    {
        Http::fake([
            'https://api.tokovoucher.net/v1/transaksi' => Http::response([
                'status' => 'sukses',
                'message' => 'Transaksi Berhasil',
                'sn' => 'SN123456',
                'ref_id' => 'INV-001',
                'trx_id' => 'TRX-999',
            ], 200),
        ]);

        $client = new TokovoucherClient();
        $response = $client->post('/v1/transaksi', [
            'ref_id' => 'INV-001',
            'produk' => 'FF5',
            'tujuan' => '12345678',
            'member_code' => 'MEM_TEST',
            'signature' => 'abc',
        ]);

        $this->assertSame('sukses', $response['status']);
        $this->assertSame('TRX-999', $response['trx_id']);
        $this->assertSame('SN123456', $response['sn']);
    }

    public function test_post_throws_when_api_returns_error_status_or_message(): void
    {
        Http::fake([
            'https://api.tokovoucher.net/v1/transaksi' => Http::response([
                'status' => 0,
                'error_msg' => 'Signature Invalid',
            ], 200),
        ]);

        $client = new TokovoucherClient();

        $this->expectException(SupplierException::class);
        $this->expectExceptionMessage('Signature Invalid');

        $client->post('/v1/transaksi', []);
    }

    public function test_get_sends_query_parameters_for_balance(): void
    {
        Http::fake([
            'https://api.tokovoucher.net/member*' => Http::response([
                'status' => 1,
                'rc' => 200,
                'data' => [
                    'saldo' => 50000,
                    'nama' => 'Test Member',
                    'member_code' => 'MEM_TEST',
                ],
            ], 200),
        ]);

        $client = new TokovoucherClient();
        $response = $client->get('/member', [
            'member_code' => 'MEM_TEST',
            'signature' => 'def',
        ]);

        $this->assertSame(1, $response['status']);
        $this->assertSame(50000, $response['data']['saldo']);
    }
}
