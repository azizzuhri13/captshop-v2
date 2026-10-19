<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Pesanan/reservasi akun Disney+ yang dibuat bot partner Telegram.
 */
class DisneyPartnerOrder extends Model
{
    protected $fillable = [
        'order_id',
        'reservation_id',
        'payload_hash',
        'package_id',
        'buyer_reference',
        'buyer_username',
        'ttl_minutes',
        'status',
        'disney_account_id',
        'price',
        'currency',
        'payment_reference',
        'payment_amount',
        'payment_currency',
        'verified_at',
        'reserved_until',
        'claimed_at',
        'cancelled_at',
        'start_date',
        'end_date',
        'warranty_until',
        'otp_token',
    ];

    protected function casts(): array
    {
        return [
            'ttl_minutes' => 'integer',
            'price' => 'decimal:2',
            'payment_amount' => 'decimal:2',
            'verified_at' => 'datetime',
            'reserved_until' => 'datetime',
            'claimed_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'start_date' => 'date',
            'end_date' => 'date',
            'warranty_until' => 'date',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(DisneyAccount::class, 'disney_account_id');
    }

    public static function package(?string $packageId): ?array
    {
        $package = $packageId ? config('services.disney.packages.'.$packageId) : null;

        return $package ? array_merge(['id' => $packageId], $package) : null;
    }

    public function toApiPayload(bool $withCredentials = false): array
    {
        $package = self::package($this->package_id) ?? [];

        $payload = [
            'order_id' => $this->order_id,
            'reservation_id' => $this->reservation_id,
            'status' => $this->status,
            'package' => [
                'id' => $this->package_id,
                'name' => $package['name'] ?? $this->package_id,
                'duration_days' => $package['duration_days'] ?? null,
                'warranty_days' => $package['warranty_days'] ?? null,
                'price' => (float) $this->price,
                'currency' => $this->currency,
                'expiry_rules' => $package['expiry_rules'] ?? null,
            ],
            'buyer_reference' => $this->buyer_reference,
            'buyer_username' => $this->buyer_username,
            'ttl_minutes' => (int) $this->ttl_minutes,
            'stock_id' => $this->disney_account_id,
            'profile_id' => $this->disney_account_id,
            'otp_token' => $this->otp_token,
            'otp_url' => url('/api/internal/disney/otp/'.$this->otp_token),
            'reserved_until' => $this->reserved_until?->setTimezone('Asia/Jakarta')->toIso8601String(),
            'claimed_at' => $this->claimed_at?->setTimezone('Asia/Jakarta')->toIso8601String(),
            'cancelled_at' => $this->cancelled_at?->setTimezone('Asia/Jakarta')->toIso8601String(),
            'payment' => [
                'reference' => $this->payment_reference,
                'amount' => $this->payment_amount === null ? null : (float) $this->payment_amount,
                'currency' => $this->payment_currency,
                'verified_at' => $this->verified_at ? $this->verified_at->setTimezone('Asia/Jakarta')->toIso8601String() : null,
            ],
            'timezone' => 'Asia/Jakarta',
            'start_date' => $this->start_date?->toDateString(),
            'end_date' => $this->end_date?->toDateString(),
            'warranty_until' => $this->warranty_until?->toDateString(),
        ];

        if ($withCredentials) {
            $payload['account'] = [
                'email' => $this->account?->email,
                'password' => $this->account?->password,
                'profile' => $this->account?->profile_name,
                'pin' => $this->account?->pin,
            ];
        }

        return $payload;
    }
}
