<?php

namespace App\Services\GameValidator;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GameValidatorService implements GameValidatorInterface
{
    protected const GAME_MAP = [
        'mobile-legends' => 'MOBILE_LEGENDS',
        'mobile-legend' => 'MOBILE_LEGENDS',
        'mlbb' => 'MOBILE_LEGENDS',
        'free-fire' => 'FREEFIRE',
        'freefire' => 'FREEFIRE',
        'ff' => 'FREEFIRE',
    ];

    public function checkNickname(string $gameCode, string $userId, ?string $zoneId = null): array
    {
        $userId = trim($userId);
        $zoneId = $zoneId !== null ? trim($zoneId) : null;
        $slug = strtolower(trim($gameCode));

        $targetGame = self::GAME_MAP[$slug] ?? null;

        if (! $targetGame) {
            return [
                'valid' => false,
                'nickname' => null,
                'message' => 'Game belum didukung untuk validasi otomatis.',
                'source' => 'unsupported',
            ];
        }

        if ($targetGame === 'MOBILE_LEGENDS' && empty($zoneId)) {
            return [
                'valid' => false,
                'nickname' => null,
                'message' => 'Zone ID wajib diisi untuk Mobile Legends.',
                'source' => 'validation',
            ];
        }

        $cacheKey = "game_nick:{$targetGame}:{$userId}:".($zoneId ?? '-');

        return Cache::remember($cacheKey, now()->addHour(), function () use ($targetGame, $userId, $zoneId) {
            return $this->queryPrimary($targetGame, $userId, $zoneId)
                ?? $this->queryFallback($targetGame, $userId, $zoneId)
                ?? [
                    'valid' => false,
                    'nickname' => null,
                    'message' => 'User ID tidak ditemukan atau Server/Zone ID salah.',
                    'source' => 'not_found',
                ];
        });
    }

    /**
     * Query provider utama (Direct Publisher Gateway).
     *
     * @return array{valid: bool, nickname: ?string, message: string, source: string}|null
     */
    protected function queryPrimary(string $targetGame, string $userId, ?string $zoneId): ?array
    {
        try {
            $url = 'https://gamestoreindonesia.com/api/v1/order/prepare/'.$targetGame;
            $params = ['userId' => $userId];
            if ($zoneId) {
                $params['zoneId'] = $zoneId;
            }

            $response = Http::timeout(8)
                ->withOptions(['curl' => [CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4]])
                ->get($url, $params);

            if ($response->successful()) {
                $data = $response->json();
                $nickname = $data['data'] ?? null;

                if (! empty($nickname)) {
                    return [
                        'valid' => true,
                        'nickname' => (string) $nickname,
                        'message' => 'Akun valid.',
                        'source' => 'primary',
                    ];
                }
            }

            if ($response->status() === 404) {
                return [
                    'valid' => false,
                    'nickname' => null,
                    'message' => 'Akun tidak ditemukan. Periksa User ID & Zone ID.',
                    'source' => 'primary',
                ];
            }
        } catch (\Throwable $e) {
            Log::warning('GameValidator Primary error: '.$e->getMessage());
        }

        return null;
    }

    /**
     * Query provider fallback.
     *
     * @return array{valid: bool, nickname: ?string, message: string, source: string}|null
     */
    protected function queryFallback(string $targetGame, string $userId, ?string $zoneId): ?array
    {
        try {
            $gameSlug = ($targetGame === 'FREEFIRE') ? 'freefire' : 'mobilelegends';
            $url = 'https://cek-username.onrender.com/game/'.$gameSlug;
            $params = ['uid' => $userId];
            if ($zoneId) {
                $params['zone'] = $zoneId;
            }

            $response = Http::timeout(8)
                ->withOptions(['curl' => [CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4]])
                ->get($url, $params);

            if ($response->successful()) {
                $data = $response->json();
                $nickname = $data['data'] ?? null;

                if (! empty($nickname)) {
                    return [
                        'valid' => true,
                        'nickname' => (string) $nickname,
                        'message' => 'Akun valid.',
                        'source' => 'fallback',
                    ];
                }
            }
        } catch (\Throwable $e) {
            Log::warning('GameValidator Fallback error: '.$e->getMessage());
        }

        return null;
    }
}
