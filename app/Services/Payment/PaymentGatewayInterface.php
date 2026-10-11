<?php

namespace App\Services\Payment;

use App\Models\Transaction;

interface PaymentGatewayInterface
{
    /**
     * Nama gateway (belibayar).
     */
    public function gatewayName(): string;

    /**
     * Buat transaksi pembayaran di gateway.
     *
     * @param  array<string, mixed>  $item  Detail item pesanan:
     *                                      ['price' => int, 'quantity' => int, 'name' => string]
     * @param  array<string, mixed>  $customer  Data pembeli:
     *                                          ['name' => string, 'phone' => string, 'email' => ?string]
     * @param  array<string, mixed>  $params  Parameter tambahan (mis. method, expired_at).
     * @return array<string, mixed> Response gateway (payment_ref, payment_url, qr_string, dll).
     *
     * @throws PaymentException
     */
    public function createTransaction(Transaction $transaction, array $item, array $customer, array $params = []): array;

    /**
     * Ambil detail transaksi dari gateway.
     *
     * @return array<string, mixed>
     *
     * @throws PaymentException
     */
    public function getTransactionDetail(string $paymentRef): array;

    /**
     * Hitung signature webhook dari payload mentah.
     */
    public function calculateSignature(array $payload): string;

    /**
     * Verifikasi signature webhook.
     */
    public function verifySignature(array $payload, string $signature): bool;

    /**
     * Nama status gateway → status internal (pending/paid/failed/expired).
     */
    public function mapStatus(string $gatewayStatus): string;
}
