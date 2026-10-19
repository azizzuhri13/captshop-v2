<?php

namespace App\Services\GameValidator;

use App\Support\Credentials;
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
        'free-fire-max' => 'FREEFIRE',
        'magic-chess-go-go' => 'MCGG',
        'magic-chess' => 'MCGG',
        'mcgg' => 'MCGG',
        'pubg-mobile' => 'PUBG_MOBILE',
        'pubg' => 'PUBG_MOBILE',
        'call-of-duty-mobile' => 'CALL_OF_DUTY',
        'codm' => 'CALL_OF_DUTY',
        'arena-of-valor' => 'AOV',
        'aov' => 'AOV',
        'honor-of-kings' => 'HOK',
        'hok' => 'HOK',
        'point-blank' => 'POINT_BLANK',
        'pointblank' => 'POINT_BLANK',
        'pb' => 'POINT_BLANK',
    ];

    public function isSupported(string $gameCode): bool
    {
        return isset(self::GAME_MAP[strtolower(trim($gameCode))]);
    }

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

        if (in_array($targetGame, ['MOBILE_LEGENDS', 'MCGG'], true) && empty($zoneId)) {
            return [
                'valid' => false,
                'nickname' => null,
                'message' => 'Zone/Server ID wajib diisi untuk game ini.',
                'source' => 'validation',
            ];
        }

        $cacheKey = "game_nick:{$targetGame}:{$userId}:".($zoneId ?? '-');

        if (Cache::has($cacheKey)) {
            return Cache::get($cacheKey);
        }

        $result = $this->queryPrimary($targetGame, $userId, $zoneId)
            ?? $this->queryApigames($targetGame, $userId, $zoneId)
            ?? $this->queryFallback($targetGame, $userId, $zoneId)
            ?? [
                'valid' => false,
                'nickname' => null,
                'message' => 'User ID tidak ditemukan. Periksa kembali akun Anda.',
                'source' => 'not_found',
            ];

        if (! empty($result['valid'])) {
            Cache::put($cacheKey, $result, now()->addHour());
        }

        return $result;
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
                $msg = match ($targetGame) {
                    'FREEFIRE' => 'User ID Free Fire tidak ditemukan. Periksa kembali ID Anda.',
                    'MOBILE_LEGENDS', 'MCGG' => 'User ID atau Zone ID tidak ditemukan. Periksa kembali akun Anda.',
                    'PUBG_MOBILE' => 'User ID PUBG Mobile tidak ditemukan. Periksa kembali ID Anda.',
                    'CALL_OF_DUTY' => 'OpenID Call of Duty tidak ditemukan. Periksa kembali OpenID Anda.',
                    'AOV' => 'OpenID Arena of Valor tidak ditemukan. Periksa kembali OpenID Anda.',
                    'HOK' => 'Player ID Honor of Kings tidak ditemukan. Periksa kembali ID Anda.',
                    'POINT_BLANK' => 'Username ID Point Blank tidak ditemukan.',
                    default => 'ID Akun tidak ditemukan. Periksa kembali akun Anda.',
                };

                return [
                    'valid' => false,
                    'nickname' => null,
                    'message' => $msg,
                    'source' => 'primary',
                ];
            }
        } catch (\Throwable $e) {
            Log::warning('GameValidator Primary error: '.$e->getMessage());
        }

        return null;
    }

    /**
     * Query provider APIGames (khusus Free Fire & ML).
     *
     * @return array{valid: bool, nickname: ?string, message: string, source: string}|null
     */
    protected function queryApigames(string $targetGame, string $userId, ?string $zoneId): ?array
    {
        if ($targetGame !== 'FREEFIRE') {
            return null;
        }

        $merchantId = (string) Credentials::get('apigames_merchant_id', env('APIGAMES_MERCHANT_ID', 'M230704TTLJ2253PV'));
        $secretKey = (string) Credentials::get('apigames_secret_key', env('APIGAMES_SECRET_KEY', '60915b9ae55659e0ef5a917516007fdf4a9f36adfd6c7b071ea5b0daf25867e7'));

        if (empty($merchantId) || empty($secretKey)) {
            return null;
        }

        try {
            $sig = md5($merchantId.$secretKey);
            $url = "https://v1.apigames.id/merchant/{$merchantId}/cek-username/freefire";
            $response = Http::timeout(5)->get($url, [
                'user_id' => $userId,
                'signature' => $sig,
            ]);

            if ($response->successful()) {
                $data = $response->json();
                if (! empty($data['data']['is_valid']) && ! empty($data['data']['username'])) {
                    return [
                        'valid' => true,
                        'nickname' => (string) $data['data']['username'],
                        'message' => 'Akun valid.',
                        'source' => 'apigames',
                    ];
                }
            }
        } catch (\Throwable $e) {
            Log::warning('GameValidator APIGames error: '.$e->getMessage());
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
