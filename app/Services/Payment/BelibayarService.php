<?php

namespace App\Services\Payment;

use App\Models\PaymentChannel;
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

    public function normalizeCharge(array $response, Transaction $transaction, PaymentChannel $channel): array
    {
        return $this->normalize($response, $transaction, (string) $channel->code, true);
    }

    public function normalizeDetail(array $response, Transaction $transaction): array
    {
        return $this->normalize($response, $transaction, null, false);
    }

    /**
     * Bentuk kanonik Belibayar. Dipisah dari OrderService supaya kontrak
     * "respons tidak lengkap = gagal" tetap satu tempat dan bisa diuji
     * terpisah dari alur checkout.
     *
     * @param  array<string, mixed>  $response
     * @return array<string, mixed>
     *
     * @throws PaymentException
     */
    private function normalize(array $response, Transaction $transaction, ?string $expectedChannel, bool $requireFreshExpiry): array
    {
        $reference = $response['reference'] ?? null;
        $providerRef = $response['transaction_id'] ?? null;

        if (! is_string($reference) || $reference === '' || $reference !== (string) $transaction->invoice_id) {
            throw PaymentException::requestFailed('Respons pembayaran Belibayar tidak lengkap atau tidak cocok.');
        }

        if (! is_string($providerRef) || $providerRef === '') {
            throw PaymentException::requestFailed('Respons Belibayar tidak memuat ID transaksi.');
        }

        if (! is_int($response['amount'] ?? null) || $response['amount'] !== (int) round((float) $transaction->total)) {
            throw PaymentException::requestFailed('Nominal Belibayar tidak sama dengan total pesanan.');
        }

        $channel = $response['payment_channel'] ?? null;

        if (! is_string($channel) || $channel === '') {
            throw PaymentException::requestFailed('Respons Belibayar tidak memuat channel pembayaran.');
        }

        if ($expectedChannel !== null && $channel !== $expectedChannel) {
            throw PaymentException::requestFailed('Channel pembayaran Belibayar tidak sesuai permintaan.');
        }

        $status = $response['status'] ?? null;

        if (! is_string($status) || $status === '') {
            throw PaymentException::requestFailed('Respons Belibayar tidak memuat status transaksi.');
        }

        $expiresAt = $response['expired_at'] ?? null;

        if (! is_int($expiresAt)) {
            throw PaymentException::requestFailed('Respons Belibayar tidak memuat waktu kedaluwarsa.');
        }

        if ($requireFreshExpiry && $expiresAt <= now()->timestamp) {
            throw PaymentException::requestFailed('Waktu kedaluwarsa Belibayar sudah lewat.');
        }

        return [
            'merchant_ref' => $reference,
            'provider_ref' => $providerRef,
            'merchant_amount' => $response['amount'],
            'payable_amount' => $response['amount'],
            'channel' => $channel,
            'status' => $this->mapStatus($status),
            'expires_at' => $expiresAt,
            'payment_url' => $this->stringOrNull($response['payment_url'] ?? null),
            'va_number' => $this->stringOrNull($response['va_number'] ?? null),
            'qr_string' => $this->stringOrNull($response['qr_content'] ?? $response['qr_string'] ?? null),
            'qr_url' => $this->stringOrNull($response['qr_url'] ?? $response['qr_code'] ?? null),
        ];
    }

    private function stringOrNull(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
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
