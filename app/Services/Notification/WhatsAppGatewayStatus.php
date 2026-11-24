<?php

namespace App\Services\Notification;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class WhatsAppGatewayStatus
{
    /**
     * Query the local gateway without exposing its credentials or response body.
     *
     * @return array{state: string, detail: string, sender: ?string, last_connected_at: ?string, last_disconnected_at: ?string, queue: array{queued: int, retryable: int, failed: int}}
     */
    public function check(): array
    {
        $url = LocalGatewayUrl::sanitize((string) config('services.whatsapp_gateway.url', ''));
        $key = (string) config('services.whatsapp_gateway.key', '');
        if ($key === '' || $url === null) {
            return $this->unavailable();
        }

        try {
            $response = Http::acceptJson()
                ->connectTimeout(1)
                ->timeout(1)
                ->withHeaders(['X-Internal-Key' => $key])
                ->get($url.'/v1/session/status');

            $payload = $response->json();
            if ($response->status() !== 200 || ! is_array($payload)) {
                return $this->unavailable();
            }

            return $this->mapPayload($payload);
        } catch (\Throwable) {
            // Do not record exception text: it may contain the URL, headers, or
            // implementation details from the private local service.
            Log::warning('Pemeriksaan status WhatsApp gateway gagal.');

            return $this->unavailable();
        }
    }


    /** @param array<string, mixed> $payload */
    private function mapPayload(array $payload): array
    {
        $gatewayState = strtolower(trim((string) ($payload['state'] ?? '')));
        $queue = $payload['queue'] ?? null;
        if (! in_array($gatewayState, ['connected', 'unpaired', 'connecting', 'logged_out', 'degraded'], true) || ! is_array($queue)) {
            return $this->unavailable();
        }

        $counts = [];
        foreach (['queued', 'retryable', 'failed_terminal'] as $key) {
            $value = $queue[$key] ?? null;
            if (! is_int($value) && ! (is_string($value) && ctype_digit($value))) {
                return $this->unavailable();
            }
            $counts[$key] = max(0, (int) $value);
        }

        return [
            'state' => $gatewayState === 'connected' ? 'connected' : 'disconnected',
            'detail' => $gatewayState === 'connected' ? 'Gateway terhubung dan siap menerima notifikasi.' : 'Gateway tidak terhubung.',
            'sender' => $this->maskSender($payload['sender'] ?? null),
            'last_connected_at' => $this->safeTimestamp($payload['last_connected_at'] ?? null),
            'last_disconnected_at' => $this->safeTimestamp($payload['last_disconnected_at'] ?? null),
            'queue' => [
                'queued' => $counts['queued'],
                'retryable' => $counts['retryable'],
                'failed' => $counts['failed_terminal'],
            ],
        ];
    }

    private function maskSender(mixed $sender): ?string
    {
        if (! is_array($sender)) {
            return null;
        }

        $candidate = (string) ($sender['display'] ?? $sender['jid'] ?? '');
        $digits = preg_replace('/\D+/', '', $candidate) ?? '';
        if ($digits === '') {
            return null;
        }

        return strlen($digits) > 7 ? substr($digits, 0, 5).'••••'.substr($digits, -3) : substr($digits, 0, 2).'•••';
    }

    private function safeTimestamp(mixed $timestamp): ?string
    {
        if (! is_string($timestamp) || $timestamp === '') {
            return null;
        }

        try {
            return (new \DateTimeImmutable($timestamp))->format(DATE_ATOM);
        } catch (\Throwable) {
            return null;
        }
    }

    /** @return array{state: string, detail: string, sender: ?string, last_connected_at: ?string, last_disconnected_at: ?string, queue: array{queued: int, retryable: int, failed: int}} */
    private function unavailable(): array
    {
        return [
            'state' => 'unavailable',
            'detail' => 'Status gateway tidak tersedia saat ini.',
            'sender' => null,
            'last_connected_at' => null,
            'last_disconnected_at' => null,
            'queue' => ['queued' => 0, 'retryable' => 0, 'failed' => 0],
        ];
    }
}
