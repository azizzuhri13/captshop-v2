<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Penanda kesegaran pricelist DigiFlazz + daftar seller yang diblokir.
 *
 * Hanya cache yang ditulis oleh pengambilan live (envelope berisi `fetched_at`)
 * yang boleh dipakai sebagai bukti ketersediaan. File lama berbentuk array polos
 * tidak punya waktu ambil yang bisa dipercaya, jadi tidak pernah dipakai untuk
 * mengaktifkan produk maupun membuka checkout.
 */
final class DigiflazzCatalog
{
    public const TTL_MINUTES = 60;

    public static function path(): string
    {
        $configured = (string) config('supplier.digiflazz.pricelist.cache_path');

        return $configured !== '' ? $configured : storage_path('app/digiflazz/pricelist.json');
    }

    public static function ttlMinutes(): int
    {
        return max(1, (int) config('supplier.digiflazz.pricelist.max_age_minutes', self::TTL_MINUTES));
    }

    public static function isFresh(?CarbonInterface $now = null): bool
    {
        return self::freshItems($now) !== null;
    }

    /**
     * Baris pricelist untuk satu SKU, hanya bila snapshot masih segar.
     *
     * @return array<string, mixed>|null
     */
    public static function freshRow(string $sku): ?array
    {
        foreach (self::freshItems() ?? [] as $row) {
            if (is_array($row) && (string) ($row['buyer_sku_code'] ?? '') === $sku) {
                return $row;
            }
        }

        return null;
    }

    /**
     * Baris pricelist dianggap tersedia hanya bila kedua flag benar-benar true
     * dan seller-nya tidak diblokir.
     *
     * @param  array<string, mixed>|null  $row
     */
    public static function isAvailableRow(?array $row): bool
    {
        return $row !== null
            && filter_var($row['buyer_product_status'] ?? false, FILTER_VALIDATE_BOOL) === true
            && filter_var($row['seller_product_status'] ?? false, FILTER_VALIDATE_BOOL) === true
            && ! self::sellerBlocked($row['seller_name'] ?? null);
    }

    public static function normalizeSeller(?string $seller): string
    {
        return strtoupper((string) preg_replace('/\s+/u', ' ', trim((string) $seller)));
    }

    /**
     * @return array<int, string>
     */
    public static function blockedSellers(): array
    {
        $raw = config('supplier.digiflazz.blocked_sellers', '');
        $list = is_array($raw) ? $raw : explode(',', (string) $raw);

        return array_values(array_filter(array_map([self::class, 'normalizeSeller'], $list), fn ($n) => $n !== ''));
    }

    public static function sellerBlocked(?string $seller): bool
    {
        $name = self::normalizeSeller($seller);

        return $name !== '' && in_array($name, self::blockedSellers(), true);
    }

    /**
     * Item pricelist dari snapshot segar, atau null bila tidak ada / sudah basi.
     *
     * @return array<int, array<string, mixed>>|null
     */
    private static function freshItems(?CarbonInterface $now = null): ?array
    {
        $path = self::path();

        if (! is_file($path)) {
            return null;
        }

        $raw = json_decode((string) @file_get_contents($path), true);

        if (! is_array($raw) || ! isset($raw['items']) || ! is_array($raw['items']) || $raw['items'] === []) {
            return null;
        }

        $fetchedAt = self::parseFetchedAt($raw['fetched_at'] ?? null);

        if ($fetchedAt === null) {
            return null;
        }

        $now ??= CarbonImmutable::now();
        $ageMinutes = ($now->getTimestamp() - $fetchedAt->getTimestamp()) / 60;

        return $ageMinutes <= self::ttlMinutes() ? array_values($raw['items']) : null;
    }

    private static function parseFetchedAt(mixed $value): ?CarbonInterface
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }
}
