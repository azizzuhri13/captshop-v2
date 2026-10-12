<?php

namespace App\Services\Payment;

use App\Support\Credentials;
use Illuminate\Support\Facades\Http;

class BelibayarClient
{
    private function settings(): array
    {
        $key = Credentials::get('belibayar_api_key', config('payment.belibayar.api_key'));
        $secret = Credentials::get('belibayar_secret_key', config('payment.belibayar.secret_key'));

        $mode = Credentials::get('belibayar_mode', config('payment.belibayar.mode', 'sandbox'));
        if (is_string($key)) {
            if (str_starts_with($key, 'byr_live_')) {
                $mode = 'live';
            } elseif (str_starts_with($key, 'byr_test_')) {
                $mode = 'sandbox';
            }
        }
        if (! in_array($mode, ['sandbox', 'live'], true)) {
            throw PaymentException::notConfigured();
        }
        if (! $key || ! $secret || ! str_starts_with($key, $mode === 'sandbox' ? 'byr_test_' : 'byr_live_')) {
            throw PaymentException::notConfigured();
        }
        $base = rtrim((string) config('payment.belibayar.base_url'), '/');
        if ($base !== 'https://api.belibayar.id') {
            throw PaymentException::notConfigured();
        }
        return [$key, $secret, $base, $mode === 'sandbox' ? '/direct/v1/sandbox' : '/direct/v1'];
    }

    public function charge(array $payload): array
    {
        [$key, $secret, $base, $prefix] = $this->settings();
        $body = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        try {
            $response = Http::timeout(20)->withOptions([
                'curl' => [
                    CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
                ],
            ])->withHeaders([
                'X-Api-Key' => $key,
                'X-Signature' => hash_hmac('sha256', $body, $secret),
                'Content-Type' => 'application/json',
            ])->withBody($body, 'application/json')->post($base.$prefix.'/payment/charge');
            return $this->unwrap($response, 'charge');
        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            throw PaymentException::requestFailed('Koneksi Belibayar gagal atau timeout; periksa status referensi sebelum mencoba ulang.');
        }
    }

    public function status(string $reference): array
    {
        [$key, $secret, $base, $prefix] = $this->settings();
        if (! preg_match('/^[A-Za-z0-9_-]+$/D', $reference)) {
            throw PaymentException::requestFailed('Referensi pembayaran tidak valid.');
        }
        $timestamp = (string) time();
        try {
            $response = Http::timeout(20)->withOptions([
                'curl' => [
                    CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
                ],
            ])->withHeaders([
                'X-Api-Key' => $key,
                'X-Timestamp' => $timestamp,
                'X-Signature' => hash_hmac('sha256', ':'.$timestamp, $secret),
            ])->get($base.$prefix.'/payment/status/'.$reference);
            return $this->unwrap($response, 'status');
        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            throw PaymentException::requestFailed('Koneksi status Belibayar gagal atau timeout; coba lagi beberapa saat.');
        }
    }

    private function unwrap($response, string $operation): array
    {
        if (! $response->successful() || $response->json('success') !== true || ! is_array($response->json('data'))) {
            $message = $response->json('messages') ?? $response->json('message') ?? ('HTTP '.$response->status());
            throw PaymentException::requestFailed('Belibayar '.$operation.' gagal: '.$message);
        }
        return $response->json('data');
    }
}
