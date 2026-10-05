<?php

namespace App\Services\Payment;

use App\Support\Credentials;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

class TripayClient
{
    public function __construct(
        protected ?string $apiKey = null,
        protected ?string $baseUrl = null,
    ) {
        // Prioritas: nilai eksplisit (test) > site_settings (admin panel) > config/.env.
        $this->apiKey ??= Credentials::get('tripay_api_key', config('payment.tripay.api_key'));
        $this->baseUrl ??= rtrim((string) Credentials::get('tripay_base_url', config('payment.tripay.base_url')), '/');
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     *
     * @throws PaymentException
     */
    public function get(string $endpoint, array $query = []): array
    {
        $response = $this->client()->get($endpoint, $query);

        if (! $response->successful()) {
            throw PaymentException::requestFailed(
                'Tripay GET '.$endpoint.' gagal: HTTP '.$response->status()
            );
        }

        return $this->unwrap($endpoint, $response->json());
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     *
     * @throws PaymentException
     */
    public function post(string $endpoint, array $data): array
    {
        $response = $this->client()->post($endpoint, $data);

        if (! $response->successful()) {
            throw PaymentException::requestFailed(
                'Tripay POST '.$endpoint.' gagal: HTTP '.$response->status()
            );
        }

        return $this->unwrap($endpoint, $response->json());
    }

    /**
     * Validasi response sukses Tripay (success == true) dan ambil elemen data.
     *
     * @param  array<string, mixed>|null  $json
     * @return array<string, mixed>
     *
     * @throws PaymentException
     */
    protected function unwrap(string $endpoint, ?array $json): array
    {
        if (! is_array($json)) {
            throw PaymentException::requestFailed('Tripay '.$endpoint.' mengembalikan response tidak valid.');
        }

        if (($json['success'] ?? false) !== true) {
            $message = $json['message'] ?? 'unknown error';

            throw PaymentException::requestFailed('Tripay '.$endpoint.' error: '.$message);
        }

        return is_array($json['data'] ?? null) ? $json['data'] : [];
    }

    protected function client(): PendingRequest
    {
        if (empty($this->apiKey)) {
            throw PaymentException::notConfigured();
        }

        return Http::baseUrl($this->baseUrl)
            ->timeout(30)
            ->withHeaders([
                'Authorization' => 'Bearer '.$this->apiKey,
            ]);
    }
}
