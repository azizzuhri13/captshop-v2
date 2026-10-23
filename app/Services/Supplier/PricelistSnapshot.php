<?php

namespace App\Services\Supplier;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Snapshot daftar harga DigiFlazz beserta metadata kesegaran data.
 *
 * Tujuan: pemanggil (command sinkronisasi) harus bisa membedakan data yang
 * benar-benar baru diambil dari API dengan data cache yang mungkin sudah basi.
 * Tanpa metadata ini, cache lama bisa dipakai untuk menaikkan/menonaktifkan
 * produk sehingga katalog menampilkan SKU yang sebenarnya sudah mati.
 */
final class PricelistSnapshot
{
    public const SOURCE_LIVE = 'live';

    public const SOURCE_CACHE = 'cache';

    /**
     * @param  array<int, array<string, mixed>>  $items
     */
    public function __construct(
        public readonly array $items,
        public readonly CarbonInterface $fetchedAt,
        public readonly string $source,
        public readonly bool $legacyCache = false,
        public readonly ?string $fallbackReason = null,
    ) {}

    /**
     * @param  array<int, array<string, mixed>>  $items
     */
    public static function live(array $items, ?CarbonInterface $now = null): self
    {
        return new self($items, $now ?? CarbonImmutable::now(), self::SOURCE_LIVE);
    }

    public function count(): int
    {
        return count($this->items);
    }

    public function isFromCache(): bool
    {
        return $this->source === self::SOURCE_CACHE;
    }

    /**
     * Umur data dalam menit (tidak pernah negatif walau jam server mundur).
     */
    public function ageMinutes(?CarbonInterface $now = null): int
    {
        $now ??= CarbonImmutable::now();
        $delta = $now->getTimestamp() - $this->fetchedAt->getTimestamp();

        return max(0, (int) floor($delta / 60));
    }

    public function isFresh(int $maxAgeMinutes, ?CarbonInterface $now = null): bool
    {
        return $this->ageMinutes($now) <= max(0, $maxAgeMinutes);
    }

    /**
     * Label umur yang enak dibaca di laporan Telegram / log.
     */
    public function ageLabel(?CarbonInterface $now = null): string
    {
        $minutes = $this->ageMinutes($now);
        if ($minutes < 60) {
            return $minutes.' menit';
        }

        $hours = intdiv($minutes, 60);
        $rest = $minutes % 60;

        return $rest === 0 ? $hours.' jam' : $hours.' jam '.$rest.' menit';
    }
}
