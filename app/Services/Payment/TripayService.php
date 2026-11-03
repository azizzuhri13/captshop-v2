<?php

namespace App\Services\Payment;

use App\Models\PaymentChannel;
use App\Models\Transaction;
use App\Support\Credentials;

/**
 * Gateway Tripay (Closed Payment).
 *
 * Yang dijaga di sini:
 *  - channel internal (BCA, QRIS, ...) dipetakan ke kode metode Tripay lewat
 *    config/payment.php; kode yang tidak dikenal ditolak, bukan ditebak;
 *  - normalisasi fail-closed: respons tanpa merchant_ref/reference/amount yang
 *    cocok dengan invoice tidak pernah dikembalikan sebagai instruksi bayar;
 *  - signature callback hanya diverifikasi dari raw body (payload hasil decode
 *    bisa berbeda byte-nya dengan yang ditandatangani Tripay).
 */
class TripayService implements PaymentGatewayInterface
{
    private const GATEWAY = 'tripay';

    public function __construct(private TripayClient $client) {}

    public function gatewayName(): string
    {
        return self::GATEWAY;
    }

    public function createTransaction(Transaction $transaction, array $item, array $customer, array $params = []): array
    {
        $channelCode = (string) ($params['method'] ?? $transaction->payment_channel);
        $method = $this->tripayMethod($channelCode);

        $email = $customer['email'] ?? null;
        if (! is_string($email) || trim($email) === '') {
            throw PaymentException::requestFailed('Email pembeli wajib diisi untuk pembayaran Tripay.');
        }

        $amount = (int) round((float) $transaction->total);
        if ($amount <= 0) {
            throw PaymentException::requestFailed('Nominal pesanan tidak valid.');
        }

        $payload = [
            'method' => $method,
            'merchant_ref' => (string) $transaction->invoice_id,
            'amount' => $amount,
            'customer_name' => (string) ($customer['name'] ?? '') ?: 'Pelanggan',
            'customer_email' => $email,
            'customer_phone' => (string) ($customer['phone'] ?? '') ?: '08000000000',
            'order_items' => [[
                'sku' => (string) ($params['sku'] ?? $transaction->invoice_id),
                'name' => mb_substr((string) ($item['name'] ?? 'Pesanan'), 0, 100),
                'price' => $amount,
                'quantity' => 1,
            ]],
        ];

        // Tripay memakai menit kedaluwarsa (maks 24 jam), bukan timestamp.
        if ($transaction->expired_at !== null) {
            $minutes = (int) ceil(($transaction->expired_at->timestamp - now()->timestamp) / 60);

            if ($minutes > 0) {
                $payload['expired_time'] = min($minutes, 24 * 60);
            }
        }

        if (isset($params['return_url']) && is_string($params['return_url']) && $params['return_url'] !== '') {
            $payload['return_url'] = $params['return_url'];
        }

        return $this->client->createTransaction($payload);
    }

    public function getTransactionDetail(string $paymentRef): array
    {
        return $this->client->transactionDetail($paymentRef);
    }

    public function normalizeCharge(array $response, Transaction $transaction, PaymentChannel $channel): array
    {
        $expected = $this->tripayMethodOrNull((string) $channel->code);
        $normalized = $this->normalize($response, $transaction);

        if ($expected !== null && strtoupper($normalized['channel']) !== $expected) {
            throw PaymentException::requestFailed('Channel pembayaran Tripay tidak sesuai permintaan.');
        }

        return $normalized;
    }

    public function normalizeDetail(array $response, Transaction $transaction): array
    {
        return $this->normalize($response, $transaction);
    }

    /**
     * Bentuk kanonik bersama untuk create & detail.
     *
     * @param  array<string, mixed>  $response
     * @return array<string, mixed>
     *
     * @throws PaymentException
     */
    private function normalize(array $response, Transaction $transaction): array
    {
        $merchantRef = $response['merchant_ref'] ?? null;
        $reference = $response['reference'] ?? null;
        $method = $response['payment_method'] ?? null;
        $status = $response['status'] ?? null;

        if (! is_string($merchantRef) || $merchantRef === ''
            || $merchantRef !== (string) $transaction->invoice_id) {
            throw PaymentException::requestFailed('Respons Tripay tidak cocok dengan invoice pesanan.');
        }

        if (! is_string($reference) || $reference === '') {
            throw PaymentException::requestFailed('Respons Tripay tidak memuat referensi transaksi.');
        }

        if (! is_string($method) || $method === '') {
            throw PaymentException::requestFailed('Respons Tripay tidak memuat metode pembayaran.');
        }

        if (! is_string($status) || $status === '') {
            throw PaymentException::requestFailed('Respons Tripay tidak memuat status transaksi.');
        }

        $amount = $this->toInt($response['amount'] ?? null);
        $total = (int) round((float) $transaction->total);

        if ($amount === null || $amount !== $total) {
            throw PaymentException::requestFailed('Nominal Tripay tidak sama dengan total pesanan.');
        }

        $feeCustomer = $this->toInt($response['fee_customer'] ?? null) ?? 0;
        $expiresAt = $this->toInt($response['expired_time'] ?? null);

        return [
            'merchant_ref' => $merchantRef,
            'provider_ref' => $reference,
            'merchant_amount' => $amount,
            'payable_amount' => $amount + $feeCustomer,
            'channel' => $method,
            'status' => $this->mapStatus($status),
            'expires_at' => $expiresAt,
            'payment_url' => $this->stringOrNull($response['checkout_url'] ?? $response['pay_url'] ?? null),
            'va_number' => $this->stringOrNull($response['pay_code'] ?? null),
            'qr_string' => $this->stringOrNull($response['qr_string'] ?? null),
            'qr_url' => $this->stringOrNull($response['qr_url'] ?? null),
        ];
    }

    /**
     * Signature callback Tripay = HMAC SHA256(raw body, private key).
     *
     * Metode array ini hanya untuk diagnosa; ia TIDAK menghasilkan signature
     * yang sah di sisi Tripay dan verifySignature() selalu false.
     */
    public function calculateSignature(array $payload): string
    {
        $privateKey = $this->privateKey();

        return hash_hmac('sha256', json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), $privateKey);
    }

    public function verifySignature(array $payload, string $signature): bool
    {
        // Hanya raw body yang boleh diautentikasi; lihat verifyWebhook().
        return false;
    }

    public function verifyWebhook(string $rawBody, string $signature): bool
    {
        if ($signature === '') {
            return false;
        }

        $privateKey = Credentials::get('tripay_private_key', config('payment.tripay.private_key'));

        if (! is_string($privateKey) || $privateKey === '') {
            return false;
        }

        return hash_equals(hash_hmac('sha256', $rawBody, $privateKey), $signature);
    }

    public function mapStatus(string $gatewayStatus): string
    {
        return match (strtolower(trim($gatewayStatus))) {
            'paid' => 'paid',
            'expired' => 'expired',
            'failed', 'refund' => 'failed',
            // unpaid/pending dan status tak dikenal tetap pending: tidak pernah
            // memicu fulfillment, hanya menunggu callback/detail berikutnya.
            default => 'pending',
        };
    }

    /**
     * @throws PaymentException
     */
    private function privateKey(): string
    {
        $privateKey = Credentials::get('tripay_private_key', config('payment.tripay.private_key'));

        if (! is_string($privateKey) || $privateKey === '') {
            throw PaymentException::notConfigured();
        }

        return $privateKey;
    }

    /**
     * @throws PaymentException
     */
    private function tripayMethod(string $channelCode): string
    {
        $method = $this->tripayMethodOrNull($channelCode);

        if ($method === null) {
            throw PaymentException::requestFailed('Channel pembayaran tidak didukung Tripay.');
        }

        return $method;
    }

    private function tripayMethodOrNull(string $channelCode): ?string
    {
        $map = config('payment.tripay.channels', []);
        $code = strtoupper(trim($channelCode));

        if (! is_array($map) || ! isset($map[$code]) || ! is_string($map[$code]) || $map[$code] === '') {
            return null;
        }

        return $map[$code];
    }

    private function toInt(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_float($value) && floor($value) === $value) {
            return (int) $value;
        }

        if (is_string($value) && preg_match('/^-?\d+$/D', $value) === 1) {
            return (int) $value;
        }

        return null;
    }

    private function stringOrNull(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
