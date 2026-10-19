<?php

namespace App\Services\Supplier;

use App\Support\Credentials;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

class TokovoucherClient
{
    public function __construct(
        protected ?string $baseUrl = null,
    ) {
        $this->baseUrl ??= rtrim((string) Credentials::get('tokovoucher_base_url', config('supplier.tokovoucher.base_url', 'https://api.tokovoucher.net')), '/');
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     *
     * @throws SupplierException
     */
    public function post(string $endpoint, array $data): array
    {
        $response = $this->request()->post(ltrim($endpoint, '/'), $data);

        if (! $response->successful()) {
            throw SupplierException::requestFailed(
                'Tokovoucher '.$endpoint.' gagal: HTTP '.$response->status()
            );
        }

        $json = $response->json();

        if (! is_array($json)) {
            throw SupplierException::requestFailed('Tokovoucher '.$endpoint.' mengembalikan response tidak valid.');
        }

        // Tokovoucher return status: 0 with error_msg when request fails validation/signature
        if (isset($json['status']) && ($json['status'] === 0 || $json['status'] === '0')) {
            $error = $json['error_msg'] ?? $json['message'] ?? 'Permintaan gagal';
            throw SupplierException::requestFailed('Tokovoucher error: '.$error);
        }

        return $json;
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     *
     * @throws SupplierException
     */
    public function get(string $endpoint, array $query = []): array
    {
        $response = $this->request()->get(ltrim($endpoint, '/'), $query);

        if (! $response->successful()) {
            throw SupplierException::requestFailed(
                'Tokovoucher '.$endpoint.' gagal: HTTP '.$response->status()
            );
        }

        $json = $response->json();

        if (! is_array($json)) {
            throw SupplierException::requestFailed('Tokovoucher '.$endpoint.' mengembalikan response tidak valid.');
        }

        if (isset($json['status']) && ($json['status'] === 0 || $json['status'] === '0')) {
            $error = $json['error_msg'] ?? $json['message'] ?? 'Permintaan gagal';
            throw SupplierException::requestFailed('Tokovoucher error: '.$error);
        }

        return $json;
    }

    protected function request(): PendingRequest
    {
        if (! Credentials::get('tokovoucher_member_code', config('supplier.tokovoucher.member_code'))
            || ! Credentials::get('tokovoucher_secret_key', config('supplier.tokovoucher.secret_key'))) {
            throw SupplierException::notConfigured();
        }

        return Http::baseUrl($this->baseUrl)
            ->timeout(60)
            ->withOptions([
                'curl' => [
                    CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
                ],
            ])
            ->acceptJson();
    }
}
