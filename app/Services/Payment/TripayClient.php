<?php

namespace App\Services\Payment;

use App\Models\Transaction;
use App\Support\Credentials;
use Illuminate\Support\Facades\Http;

/**
 * Klien HTTP Tripay (Closed Payment).
 *
 * Keamanan yang dijaga di sini:
 *  - kredensial tidak pernah ditulis ke log/pesan exception;
 *  - base URL DITURUNKAN dari mode (sandbox/live), tidak pernah dibaca dari
 *    config/env. Env TRIPAY_BASE_URL lama di host produksi berisi URL live,
 *    sehingga membacanya akan membuat mode sandbox mustahil dan bisa
 *    mengarahkan request (beserta Authorization header) ke host lain;
 *  - signature request = HMAC SHA256(merchant_code . merchant_ref . amount)
 *    dengan private key, sesuai kontrak Closed Payment Tripay.
 */
class TripayClient
{
    /** Origin resmi Tripay per mode. Tidak ada mode lain yang diterima. */
    private const BASE_URLS = [
        'sandbox' => 'https://tripay.co.id/api-sandbox',
        'live' => 'https://tripay.co.id/api',
    ];

    /**
     * @return array{0:string,1:string,2:string,3:string,4:string} [apiKey, privateKey, merchantCode, baseUrl, mode]
     *
     * @throws PaymentException
     */
    private function settings(): array
    {
        $apiKey = Credentials::get('tripay_api_key', config('payment.tripay.api_key'));
        $privateKey = Credentials::get('tripay_private_key', config('payment.tripay.private_key'));
        $merchantCode = Credentials::get('tripay_merchant_code', config('payment.tripay.merchant_code'));
        $mode = strtolower(trim((string) Credentials::get('tripay_mode', config('payment.tripay.mode', 'sandbox'))));

        if (! isset(self::BASE_URLS[$mode])) {
            throw PaymentException::notConfigured();
        }

        // Base URL hanya berasal dari mode; tidak ada override config yang
        // bisa membelokkan request. Nilai kredensial juga divalidasi bentuknya
        // agar nilai rusak (spasi/newline/URL) gagal sebelum ada request.
        $base = self::BASE_URLS[$mode];

        if (! is_string($apiKey) || preg_match('/^[^\s]{8,512}\z/', $apiKey) !== 1
            || ! is_string($privateKey) || preg_match('/^[^\s]{8,512}\z/', $privateKey) !== 1
            || ! is_string($merchantCode) || preg_match('/^[A-Za-z0-9]{3,32}\z/', $merchantCode) !== 1) {
            throw PaymentException::notConfigured();
        }

        return [$apiKey, $privateKey, $merchantCode, $base, $mode];
    }

    /**
     * Apakah kredensial + mode valid tanpa melakukan request jaringan.
     * Dipakai panel admin sebagai syarat mengaktifkan Tripay.
     */
    public function isConfigured(): bool
    {
        try {
            $this->settings();

            return true;
        } catch (PaymentException) {
            return false;
        }
    }

    /**
     * Signature request Closed Payment: HMAC SHA256(merchant_code + merchant_ref + amount).
     */
    public function createSignature(string $merchantCode, string $merchantRef, int $amount, string $privateKey): string
    {
        return hash_hmac('sha256', $merchantCode.$merchantRef.$amount, $privateKey);
    }

    /**
     * POST /transaction/create.
     *
     * @param  array<string, mixed>  $payload  Payload tanpa signature.
     * @return array<string, mixed> Isi `data` dari respons Tripay.
     *
     * @throws PaymentException
     */
    public function createTransaction(array $payload): array
    {
        [$apiKey, $privateKey, $merchantCode, $base] = $this->settings();

        $merchantRef = (string) ($payload['merchant_ref'] ?? '');
        $amount = $payload['amount'] ?? null;

        if ($merchantRef === '' || ! is_int($amount) || $amount <= 0) {
            throw PaymentException::requestFailed('Payload Tripay tidak valid: merchant_ref dan amount wajib diisi.');
        }

        $payload['signature'] = $this->createSignature($merchantCode, $merchantRef, $amount, $privateKey);
        $body = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        try {
            $response = Http::timeout(20)->withOptions([
                'curl' => [
                    CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
                ],
            ])->withHeaders([
                'Authorization' => 'Bearer '.$apiKey,
                // Sebagian dokumentasi/SDK mengirim signature di body, sebagian
                // di header. Kirim keduanya agar kompatibel tanpa melemahkan apa pun.
                'X-Signature' => $payload['signature'],
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ])->withBody($body, 'application/json')->post($base.'/transaction/create');
        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            throw PaymentException::requestFailed('Koneksi Tripay gagal atau timeout; periksa status referensi sebelum mencoba ulang.');
        }

        return $this->unwrap($response, 'create');
    }

    /**
     * GET /transaction/detail?reference=...
     *
     * @return array<string, mixed>
     *
     * @throws PaymentException
     */
    public function transactionDetail(string $reference): array
    {
        [$apiKey, , , $base] = $this->settings();

        if (! preg_match('/^[A-Za-z0-9._-]+$/D', $reference)) {
            throw PaymentException::requestFailed('Referensi pembayaran tidak valid.');
        }

        try {
            $response = Http::timeout(20)->withOptions([
                'curl' => [
                    CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
                ],
            ])->withHeaders([
                'Authorization' => 'Bearer '.$apiKey,
                'Accept' => 'application/json',
            ])->get($base.'/transaction/detail', ['reference' => $reference]);
        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            throw PaymentException::requestFailed('Koneksi detail Tripay gagal atau timeout; coba lagi beberapa saat.');
        }

        return $this->unwrap($response, 'detail');
    }

    /**
     * Signature callback: HMAC SHA256(raw body, private key).
     *
     * @throws PaymentException
     */
    public function callbackSignature(string $rawBody): string
    {
        [, $privateKey] = $this->settings();

        return hash_hmac('sha256', $rawBody, $privateKey);
    }

    /**
     * @throws PaymentException
     */
    private function unwrap(\Illuminate\Http\Client\Response $response, string $operation): array
    {
        if (! $response->successful() || $response->json('success') !== true || ! is_array($response->json('data'))) {
            $message = $response->json('message') ?? ('HTTP '.$response->status());

            throw PaymentException::requestFailed('Tripay '.$operation.' gagal: '.(is_string($message) ? $message : 'respons tidak dikenali'));
        }

        return $response->json('data');
    }
}
