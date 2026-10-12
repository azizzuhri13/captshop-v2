<?php

namespace App\Services\Supplier;

use App\Models\Transaction;
use App\Support\Credentials;

class TokovoucherService implements SupplierInterface
{
    public function __construct(
        protected TokovoucherClient $client,
    ) {}

    public function supplierName(): string
    {
        return 'tokovoucher';
    }

    public function topup(Transaction $transaction, array $data): array
    {
        $product = $transaction->productItem?->product;

        if ($product === null || empty($transaction->productItem?->supplier_item_code)) {
            throw SupplierException::requestFailed('Produk/item belum terpetakan ke SKU supplier Tokovoucher.');
        }

        $refId = $transaction->invoice_id;
        $memberCode = $this->memberCode();
        $secret = $this->secretKey();

        [$tujuan, $serverId] = $this->resolveDestination($data);

        $payload = [
            'ref_id' => $refId,
            'produk' => $transaction->productItem->supplier_item_code,
            'tujuan' => $tujuan,
            'server_id' => $serverId,
            'member_code' => $memberCode,
            'signature' => md5($memberCode.':'.$secret.':'.$refId),
        ];

        $result = $this->client->post('/v1/transaksi', $payload);

        return $this->normalize($result);
    }

    public function checkBalance(): array
    {
        $memberCode = $this->memberCode();
        $secret = $this->secretKey();
        $sigDefault = (string) Credentials::get('tokovoucher_signature', config('supplier.tokovoucher.signature'));

        if (empty($sigDefault)) {
            $sigDefault = md5($memberCode.':'.$secret);
        }

        $result = $this->client->get('/member', [
            'member_code' => $memberCode,
            'signature' => $sigDefault,
        ]);

        return [
            'balance' => (float) ($result['data']['saldo'] ?? 0),
            'message' => (string) ($result['message'] ?? ''),
        ];
    }

    public function checkStatus(string $refId): array
    {
        $memberCode = $this->memberCode();
        $secret = $this->secretKey();

        $payload = [
            'ref_id' => $refId,
            'member_code' => $memberCode,
            'signature' => md5($memberCode.':'.$secret.':'.$refId),
        ];

        $result = $this->client->post('/v1/transaksi/status', $payload);

        return $this->normalize($result);
    }

    protected function memberCode(): string
    {
        return (string) Credentials::get('tokovoucher_member_code', config('supplier.tokovoucher.member_code'));
    }

    protected function secretKey(): string
    {
        return (string) Credentials::get('tokovoucher_secret_key', config('supplier.tokovoucher.secret_key'));
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{0: string, 1: string} [tujuan, serverId]
     */
    protected function resolveDestination(array $data): array
    {
        $userId = $data['user_id'] ?? null;
        $zoneId = $data['zone_id'] ?? $data['server_id'] ?? null;

        if ($userId !== null) {
            return [(string) $userId, (string) ($zoneId ?? '')];
        }

        $first = reset($data);
        $dest = is_string($first) || is_numeric($first) ? (string) $first : '';

        return [$dest, ''];
    }

    /**
     * Normalisasi response Tokovoucher ke format internal.
     *
     * @param  array<string, mixed>  $result
     * @return array<string, mixed>
     */
    protected function normalize(array $result): array
    {
        $statusStr = strtolower((string) ($result['status'] ?? ''));

        $status = match ($statusStr) {
            'sukses', 'success' => 'completed',
            'pending', 'proses' => 'processing',
            'gagal', 'failed' => 'failed',
            default => 'processing',
        };

        return [
            'supplier_ref' => (string) ($result['trx_id'] ?? $result['ref_id'] ?? ''),
            'supplier_sn' => (string) ($result['sn'] ?? $result['message'] ?? ''),
            'status' => $status,
            'message' => (string) ($result['message'] ?? ''),
            'raw' => $result,
        ];
    }
}
