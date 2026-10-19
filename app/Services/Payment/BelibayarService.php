<?php

namespace App\Services\Payment;

use App\Models\Transaction;

class BelibayarService implements PaymentGatewayInterface
{
    public function __construct(private BelibayarClient $client) {}

    public function gatewayName(): string
    {
        return 'belibayar';
    }

    public function createTransaction(Transaction $transaction, array $item, array $customer, array $params = []): array
    {
        $channel = strtoupper((string) ($params['method'] ?? $transaction->payment_channel));
        $method = match (true) {
            $channel === 'QRIS' => 'qris',
            in_array($channel, ['OVO', 'DANA', 'SPAY', 'ASTRA', 'SC'], true) => 'ewallet',
            in_array($channel, ['BCA', 'BNI', 'BRI', 'MANDIRI', 'PERMATA', 'BSI', 'MUAMALAT', 'CIMB', 'SINARMAS', 'BNC', 'MAYBANK', 'MANDIRIPC', 'BCAPC', 'INDOMARET', 'ALFAMART'], true) => 'virtual_account',
            default => throw PaymentException::requestFailed('Channel Belibayar tidak didukung.'),
        };
        $payMethod = ['method' => $method];
        if ($method !== 'qris') {
            $payMethod['channel'] = $channel;
        }
        $payload = [
            'reference' => $transaction->invoice_id,
            'amount' => (int) round($transaction->total),
            'pay_method' => $payMethod,
            'customer_name' => $customer['name'] ?: 'Pelanggan',
            'customer_email' => $customer['email'] ?: throw PaymentException::requestFailed('Email pembeli wajib diisi.'),
        ];
        if ($method === 'qris') {
            $payload['static_qr'] = false;
        }
        if ($method === 'ewallet') {
            $payload['customer_phone'] = $customer['phone'];
        }
        if ($transaction->expired_at) {
            $payload['expired_time'] = $transaction->expired_at->timestamp;
        }
        return $this->client->charge($payload);
    }

    public function getTransactionDetail(string $paymentRef): array
    {
        return $this->client->status($paymentRef);
    }

    public function verifyWebhook(string $rawBody, string $signature): bool
    {
        $secret = \App\Support\Credentials::get('belibayar_webhook_secret', config('payment.belibayar.webhook_secret'));
        return is_string($secret) && $secret !== '' && $signature !== ''
            && hash_equals(hash_hmac('sha256', $rawBody, $secret), $signature);
    }

    public function calculateSignature(array $payload): string
    {
        return hash_hmac('sha256', json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), (string) \App\Support\Credentials::get('belibayar_webhook_secret', config('payment.belibayar.webhook_secret')));
    }

    public function verifySignature(array $payload, string $signature): bool
    {
        return false; // Only raw request body may be authenticated; use verifyWebhook().
    }

    public function mapStatus(string $gatewayStatus): string
    {
        return match (strtolower($gatewayStatus)) {
            'paid' => 'paid',
            'expired', 'cancelled' => 'expired',
            'pending' => 'pending',
            default => 'pending',
        };
    }
}
