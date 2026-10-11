<?php

namespace App\Services\Payment;

use App\Support\Credentials;
use Illuminate\Support\Facades\Http;

class BelibayarClient
{
    private function settings(): array
    {
        $mode = config('payment.belibayar.mode');
        if (! in_array($mode, ['sandbox', 'live'], true)) {
            throw PaymentException::notConfigured();
        }
        $key = Credentials::get('belibayar_api_key', config('payment.belibayar.api_key'));
        $secret = Credentials::get('belibayar_secret_key', config('payment.belibayar.secret_key'));
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
            $response = Http::timeout(20)->withHeaders([
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
        $response = Http::timeout(20)->withHeaders([
            'X-Api-Key' => $key,
            'X-Timestamp' => $timestamp,
            'X-Signature' => hash_hmac('sha256', ':'.$timestamp, $secret),
        ])->get($base.$prefix.'/payment/status/'.$reference);
        return $this->unwrap($response, 'status');
    }

    private function unwrap($response, string $operation): array
    {
        if (! $response->successful() || $response->json('success') !== true || ! is_array($response->json('data'))) {
            throw PaymentException::requestFailed('Belibayar '.$operation.' gagal (HTTP '.$response->status().').');
        }
        return $response->json('data');
    }
}
