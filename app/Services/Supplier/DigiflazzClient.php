<?php

namespace App\Services\Supplier;

use App\Support\Credentials;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

class DigiflazzClient
{
    public function __construct(
        protected ?string $baseUrl = null,
    ) {
        // Prioritas: nilai eksplisit (test) > site_settings (admin panel) > config/.env.
        $this->baseUrl ??= rtrim((string) Credentials::get('digiflazz_base_url', config('supplier.digiflazz.base_url')), '/');
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     *
     * @throws SupplierException
     */
    public function post(string $endpoint, array $data): array
    {
        $response = $this->request()->post($endpoint, $data);

        if (! $response->successful()) {
            $errDetail = $response->json('data.message') ?? $response->json('message') ?? ('HTTP '.$response->status());
            $rc = $response->json('data.rc') ?? $response->json('rc') ?? $response->status();

            // 5xx/429 = gangguan sementara (boleh dicoba ulang dengan backoff).
            // 4xx = konfigurasi/permintaan salah; mengulang tidak akan menolong.
            $retryable = $response->serverError() || $response->status() === 429;

            throw SupplierException::requestFailed(
                "DigiFlazz {$endpoint} gagal ({$rc}): {$errDetail}",
                $retryable
            );
        }

        $json = $response->json();

        if (! is_array($json)) {
            throw SupplierException::requestFailed('DigiFlazz '.$endpoint.' mengembalikan response tidak valid.');
        }

        // DigiFlazz memakai bentuk {data: {...}} baik untuk sukses, pending, maupun error.
        if (isset($json['data']['status'])) {
            $status = strtolower((string) $json['data']['status']);
            if (! in_array($status, ['sukses', 'pending', 'proses'], true)) {
                $message = $json['data']['message'] ?? $json['data']['rc'] ?? 'unknown error';
                $rc = (string) ($json['data']['rc'] ?? '');
                $detail = is_array($message) ? json_encode($message) : (string) $message;

                // Status "gagal" dari supplier bersifat final: mengirim ulang
                // transaksi dengan ref_id yang sama tidak akan mengubah hasil.
                if (in_array($status, ['gagal', 'failed'], true) || $rc === '02') {
                    throw SupplierException::terminalFailure(
                        "DigiFlazz {$endpoint} error: {$detail}".($rc !== '' ? " (rc {$rc})" : '')
                    );
                }

                throw SupplierException::requestFailed(
                    "DigiFlazz {$endpoint} error: {$detail}".($rc !== '' ? " (rc {$rc})" : '')
                );
            }
        }

        return $json;
    }

    protected function request(): PendingRequest
    {
        if (! Credentials::get('digiflazz_username', config('supplier.digiflazz.username'))
            || ! Credentials::get('digiflazz_api_key', config('supplier.digiflazz.api_key'))) {
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
