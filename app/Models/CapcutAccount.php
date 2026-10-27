<?php

namespace App\Models;

use App\Services\CapcutTierService;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CapcutAccount extends Model
{
    protected $fillable = [
        'email',
        'password',
        'status',
        'channel',
        'transaction_id',
        'sold_at',
        'notes',
    ];

    protected $casts = [
        'sold_at' => 'datetime',
        'created_at' => 'datetime',
    ];

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }

    /**
     * Akun stok yang masih bisa dijual: berstatus ready dan masa berlakunya
     * (created_at + 30 hari) belum lewat.
     */
    public function scopeSellable(Builder $query): Builder
    {
        return $query->where('status', 'ready')
            ->where('created_at', '>', now()->subDays(CapcutTierService::validityDays()));
    }

    public function validUntil(): \Illuminate\Support\Carbon
    {
        return CapcutTierService::validUntil($this);
    }

    public function isExpired(): bool
    {
        return $this->validUntil()->getTimestamp() <= now()->getTimestamp();
    }

    public function remainingDays(): int
    {
        return CapcutTierService::describe($this)['remaining_days'];
    }

    /**
     * @return array{tier: string, tier_label: string, price: int, currency: string, age_days: int, remaining_days: int, valid_until: string, sellable: bool, expired: bool}
     */
    public function tierInfo(?CarbonInterface $now = null): array
    {
        return CapcutTierService::describe($this, $now);
    }

    /** Tandai akun ready yang sudah lewat 30 hari sebagai expired (tidak bisa dijual). */
    public static function expireStale(): int
    {
        return static::query()
            ->where('status', 'ready')
            ->where('created_at', '<=', now()->subDays(CapcutTierService::validityDays()))
            ->update(['status' => 'expired', 'channel' => null]);
    }

    /** Kembalikan akun yang direservasi checkout web ke stok ready. */
    public static function releaseForTransaction(int $transactionId): void
    {
        static::query()
            ->where('transaction_id', $transactionId)
            ->where('status', 'reserved')
            ->update(['status' => 'ready', 'channel' => null, 'transaction_id' => null]);
    }
}