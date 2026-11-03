<?php

namespace App\Services\Payment;

use App\Models\PaymentChannel;
use App\Models\Transaction;

interface PaymentGatewayInterface
{
    /**
     * Nama gateway (belibayar, tripay).
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
     * @return array<string, mixed> Response gateway mentah (payment_ref, payment_url, qr_string, dll).
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
     * Normalisasi respons createTransaction mentah ke bentuk kanonik yang
     * dipakai OrderService. Setiap gateway bertanggung jawab memverifikasi
     * kelengkapan field wajibnya sendiri di sini (fail-closed), sehingga
     * pemanggil tidak perlu tahu nama field masing-masing provider.
     *
     * Kunci kanonik:
     *  - merchant_ref     string  referensi merchant (harus = invoice_id kita)
     *  - provider_ref     string  ID transaksi di gateway
     *  - merchant_amount  int     nominal yang kita minta ditagih (= total pesanan)
     *  - payable_amount   int     nominal yang benar-benar dibayar pelanggan
     *                             (termasuk biaya yang dibebankan ke pelanggan)
     *  - channel          string  kode channel persis seperti di gateway
     *  - status           string  pending|paid|expired|failed
     *  - expires_at       ?int    unix timestamp kedaluwarsa
     *  - payment_url      ?string URL pembayaran/redirect
     *  - va_number        ?string nomor VA / kode bayar retail
     *  - qr_string        ?string payload QR mentah
     *  - qr_url           ?string URL gambar QR
     *
     * @param  array<string, mixed>  $response
     * @return array<string, mixed>
     *
     * @throws PaymentException
     */
    public function normalizeCharge(array $response, Transaction $transaction, PaymentChannel $channel): array;

    /**
     * Normalisasi respons detail transaksi ke bentuk kanonik yang sama
     * dengan normalizeCharge().
     *
     * @param  array<string, mixed>  $response
     * @return array<string, mixed>
     *
     * @throws PaymentException
     */
    public function normalizeDetail(array $response, Transaction $transaction): array;

    /**
     * Hitung signature webhook dari payload (dipakai untuk pengujian/diagnosa).
     */
    public function calculateSignature(array $payload): string;

    /**
     * Verifikasi signature webhook berbasis payload array.
     */
    public function verifySignature(array $payload, string $signature): bool;

    /**
     * Verifikasi signature callback dari raw body. Hanya raw body yang boleh
     * diautentikasi: json_encode ulang bisa mengubah urutan/escape byte.
     */
    public function verifyWebhook(string $rawBody, string $signature): bool;

    /**
     * Nama status gateway → status internal (pending/paid/expired/failed).
     */
    public function mapStatus(string $gatewayStatus): string;
}
