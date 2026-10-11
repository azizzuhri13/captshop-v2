<?php

namespace App\Services\Supplier;

use App\Models\Transaction;
use App\Support\Credentials;

class DigiflazzService implements SupplierInterface
{
    public function __construct(
        protected DigiflazzClient $client,
    ) {}

    public function supplierName(): string
    {
        return 'digiflazz';
    }

    public function topup(Transaction $transaction, array $data): array
    {
        $product = $transaction->productItem?->product;

        if ($product === null || empty($product->supplier_code) || empty($transaction->productItem->supplier_item_code)) {
            throw SupplierException::requestFailed('Produk/item belum terpetakan ke SKU supplier.');
        }

        $payload = [
            'username' => $this->username(),
            'buyer_sku_code' => $transaction->productItem->supplier_item_code,
            'customer_no' => $this->resolveCustomerNo($data),
            'ref_id' => $transaction->invoice_id,
            'sign' => $this->sign($transaction->invoice_id),
        ];

        if (config('supplier.digiflazz.testing')) {
            $payload['testing'] = true;
        }

        $result = $this->client->post('/transaction', $payload);

        return $this->normalize($result);
    }

    public function checkBalance(): array
    {
        $result = $this->client->post('/cek-saldo', [
            'cmd' => 'deposit',
            'username' => $this->username(),
            'sign' => md5($this->username().$this->apiKey().'depo'),
        ]);

        return [
            'balance' => (float) ($result['data']['deposit'] ?? 0),
            'message' => (string) ($result['data']['message'] ?? ''),
        ];
    }

    protected function username(): string
    {
        return (string) Credentials::get('digiflazz_username', config('supplier.digiflazz.username'));
    }

    protected function apiKey(): string
    {
        return (string) Credentials::get('digiflazz_api_key', config('supplier.digiflazz.api_key'));
    }

    /**
     * Resolve data pelanggan menjadi satu customer_no untuk DigiFlazz.
     * Produk seperti ML memerlukan format "user_id zone_id" (dipisah spasi).
     */
    protected function resolveCustomerNo(array $data): string
    {
        $fields = $data['user_id'] ?? null;
        $zone = $data['zone_id'] ?? null;

        if ($fields !== null && $zone !== null) {
            return trim($fields.' '.$zone);
        }

        $first = reset($data);

        return is_string($first) || is_numeric($first) ? (string) $first : '';
    }

    /**
     * Signature DigiFlazz: md5(username + apiKey + refId).
     */
    protected function sign(string $refId): string
    {
        return md5($this->username().$this->apiKey().$refId);
    }

    /**
     * Normalisasi response transaksi DigiFlazz ke struktur internal.
     *
     * @param  array<string, mixed>  $result
     * @return array<string, mixed>
     */
    protected function normalize(array $result): array
    {
        $data = $result['data'] ?? $result;

        $status = match (strtolower((string) ($data['status'] ?? ''))) {
            'sukses' => 'completed',
            'pending', 'proses' => 'processing',
            'gagal' => 'failed',
            default => 'processing',
        };

        return [
            'supplier_ref' => (string) ($data['ref_id'] ?? $data['buyer_sku_code'] ?? ''),
            'supplier_sn' => (string) ($data['sn'] ?? $data['message'] ?? ''),
            'status' => $status,
            'message' => (string) ($data['message'] ?? ''),
            'raw' => $result,
        ];
    }
}
