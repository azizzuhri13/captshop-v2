<?php

namespace App\Services;

use App\Models\CapcutAccount;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * Aturan harga dinamis stok akun CapCut Pro.
 *
 * Masa berlaku setiap akun stok dihitung dari `created_at` yang immutable
 * (entered_at). Akun otomatis kedaluwarsa 30 hari setelah masuk stok, dan
 * harga turun mengikuti umur akun / sisa masa aktifnya. Prioritas tertinggi
 * adalah aturan sisa masa aktif <= 7 hari (harga termurah).
 */
class CapcutTierService
{
    /** Umur maksimum akun stok sebelum otomatis kedaluwarsa (hari). */
    public const STOCK_VALIDITY_DAYS = 30;

    /** Batas sisa masa aktif yang memaksa harga clearance (hari). */
    public const CLEARANCE_REMAINING_DAYS = 7;

    /** Harga per tier; bisa ditimpa lewat config services.capcut.tiers.{code}.price. */
    public const DEFAULT_PRICES = [
        'full' => 25000,
        'mid' => 20000,
        'low' => 15000,
        'clearance' => 10000,
    ];

    /** Ambang umur akun (hari) untuk setiap tier. */
    public const TIER_MIN_AGE_DAYS = [
        'full' => 0,
        'mid' => 9,
        'low' => 16,
        'clearance' => 23,
    ];

    public static function validityDays(): int
    {
        return (int) config('services.capcut.stock_validity_days', self::STOCK_VALIDITY_DAYS);
    }

    /**
     * Tanggal (immutable) berakhirnya masa aktif sebuah akun stok.
     */
    public static function validUntil(CapcutAccount $account): Carbon
    {
        return $account->created_at->copy()->addDays(self::validityDays());
    }

    public static function price(string $tier): int
    {
        $configured = config('services.capcut.tiers.'.$tier.'.price');

        return (int) ($configured ?? self::DEFAULT_PRICES[$tier] ?? 0);
    }

    /**
     * Hitung tier, harga, dan sisa masa aktif untuk satu akun stok.
     *
     * @return array{tier: string, tier_label: string, price: int, currency: string, age_days: int, remaining_days: int, valid_until: string, sellable: bool, expired: bool}
     */
    public static function describe(CapcutAccount $account, ?CarbonInterface $now = null): array
    {
        $now = $now ? Carbon::instance($now) : now();
        $createdAt = $account->created_at->copy();
        $validUntil = $createdAt->copy()->addDays(self::validityDays());

        $ageSeconds = max(0, $now->getTimestamp() - $createdAt->getTimestamp());
        $ageDays = intdiv($ageSeconds, 86400);

        $remainingSeconds = $validUntil->getTimestamp() - $now->getTimestamp();
        $expired = $remainingSeconds <= 0;
        $remainingDays = $expired ? 0 : (int) ceil($remainingSeconds / 86400);

        // Prioritas aturan: sisa masa aktif <= 7 hari selalu clearance.
        if (! $expired && $remainingDays <= self::CLEARANCE_REMAINING_DAYS) {
            $tier = 'clearance';
        } elseif ($ageDays >= self::TIER_MIN_AGE_DAYS['low']) {
            $tier = 'low';
        } elseif ($ageDays >= self::TIER_MIN_AGE_DAYS['mid']) {
            $tier = 'mid';
        } else {
            $tier = 'full';
        }

        $status = (string) $account->status;

        return [
            'tier' => $tier,
            'tier_label' => self::label($tier),
            'price' => self::price($tier),
            'currency' => (string) config('services.capcut.currency', 'IDR'),
            'age_days' => $ageDays,
            'remaining_days' => $remainingDays,
            'valid_until' => $validUntil->toDateString(),
            'sellable' => ! $expired && $status === 'ready',
            'expired' => $expired,
        ];
    }

    public static function label(string $tier): string
    {
        return match ($tier) {
            'clearance' => 'Sisa masa aktif <= '.self::CLEARANCE_REMAINING_DAYS.' hari',
            'low' => 'Sisa masa aktif 8-14 hari',
            'mid' => 'Sisa masa aktif 15-21 hari',
            default => 'Sisa masa aktif > 21 hari',
        };
    }

    /**
     * Katalog tier beserta harga untuk dikonsumsi bot/API.
     *
     * @return array<int, array{tier: string, tier_label: string, price: int, currency: string, min_age_days: int, max_remaining_days: int|null}>
     */
    public static function catalog(): array
    {
        $rows = [
            ['tier' => 'full', 'max_remaining_days' => null],
            ['tier' => 'mid', 'max_remaining_days' => null],
            ['tier' => 'low', 'max_remaining_days' => null],
            ['tier' => 'clearance', 'max_remaining_days' => self::CLEARANCE_REMAINING_DAYS],
        ];

        return array_map(fn (array $row) => $row + [
            'tier_label' => self::label($row['tier']),
            'price' => self::price($row['tier']),
            'currency' => (string) config('services.capcut.currency', 'IDR'),
            'min_age_days' => self::TIER_MIN_AGE_DAYS[$row['tier']],
        ], $rows);
    }

    /** Akun stok dengan tier termurah (umur paling tua) yang masih bisa dijual. */
    public static function cheapestAvailable(): ?CapcutAccount
    {
        return CapcutAccount::query()->sellable()->orderBy('created_at')->orderBy('id')->first();
    }
}