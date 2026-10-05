<?php

namespace App\Services\Payment;

use App\Models\Transaction;

class TripayService implements PaymentGatewayInterface
{
    public function __construct(
        protected TripayClient $client,
    ) {}

    public function gatewayName(): string
    {
        return 'tripay';
    }

    public function createTransaction(Transaction $transaction, array $item, array $customer, array $params = []): array
    {
        $payload = [
            'method' => $params['method'] ?? $transaction->payment_channel,
            'merchant_ref' => $transaction->invoice_id,
            'amount' => (int) round($transaction->total),
            'customer_name' => $customer['name'],
            'customer_email' => $customer['email'] ?? 'no-reply@captshop.test',
            'customer_phone' => $customer['phone'],
            'order_items' => [
                [
                    'sku' => $params['sku'] ?? $transaction->invoice_id,
                    'name' => $item['name'],
                    'price' => (int) round($item['price']),
                    'quantity' => $item['quantity'] ?? 1,
                ],
            ],
            'return_url' => $params['return_url'] ?? null,
            'expired_time' => $transaction->expired_at?->getTimestamp(),
            'signature' => $this->createTransactionSignature($transaction),
        ];

        $payload = array_filter($payload, fn ($value) => $value !== null);

        return $this->client->post('/transaction/create', $payload);
    }

    public function getTransactionDetail(string $paymentRef): array
    {
        return $this->client->get('/transaction/detail', ['reference' => $paymentRef]);
    }

    /**
     * Signature untuk endpoint POST /transaction/create.
     * Format: HMAC-SHA256(merchant_code + merchant_ref + amount).
     */
    public function createTransactionSignature(Transaction $transaction): string
    {
        $data = implode('', [
            config('payment.tripay.merchant_code'),
            $transaction->invoice_id,
            (string) (int) round($transaction->total),
        ]);

        return hash_hmac('sha256', $data, config('payment.tripay.private_key'));
    }

    /**
     * Signature callback (webhook) Tripay.
     * Format: HMAC-SHA256(merchant_ref + status + amount).
     */
    public function calculateSignature(array $payload): string
    {
        $data = implode('', [
            $payload['merchant_ref'] ?? '',
            $payload['status'] ?? '',
            $payload['amount'] ?? '',
        ]);

        return hash_hmac('sha256', $data, config('payment.tripay.private_key'));
    }

    public function verifySignature(array $payload, string $signature): bool
    {
        return hash_equals($this->calculateSignature($payload), $signature);
    }

    public function mapStatus(string $gatewayStatus): string
    {
        return match (strtoupper($gatewayStatus)) {
            'UNPAID', 'PENDING' => 'pending',
            'PAID' => 'paid',
            'EXPIRED' => 'expired',
            'FAILED', 'REFUND' => 'failed',
            default => 'pending',
        };
    }
}
