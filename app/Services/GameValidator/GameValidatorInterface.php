<?php

namespace App\Services\GameValidator;

interface GameValidatorInterface
{
    /**
     * Cek validitas user ID dan dapatkan nickname game.
     *
     * @return array{
     *     valid: bool,
     *     nickname: ?string,
     *     message: string,
     *     source: string
     * }
     */
    public function checkNickname(string $gameCode, string $userId, ?string $zoneId = null): array;

    /**
     * Cek apakah game didukung untuk validasi otomatis.
     */
    public function isSupported(string $gameCode): bool;
}
