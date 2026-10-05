<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Coupon extends Model
{
    use HasFactory;

    protected $fillable = [
        'code',
        'discount_type',
        'discount_value',
        'min_purchase',
        'max_usage',
        'used_count',
        'start_at',
        'end_at',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'discount_value' => 'decimal:2',
            'min_purchase' => 'decimal:2',
            'start_at' => 'datetime',
            'end_at' => 'datetime',
            'is_active' => 'boolean',
            'max_usage' => 'integer',
            'used_count' => 'integer',
        ];
    }

    public function isValid(float $purchaseAmount = 0): bool
    {
        return $this->validationError($purchaseAmount) === null;
    }

    /**
     * Alasan kupon tidak bisa dipakai untuk pembelian sebesar $purchaseAmount;
     * null bila kupon valid. Dipakai endpoint check & OrderService agar pesan
     * error konsisten.
     */
    public function validationError(float $purchaseAmount): ?string
    {
        if (! $this->is_active) {
            return 'Kupon tidak aktif.';
        }

        $now = now();

        if ($this->start_at !== null && $this->start_at->gt($now)) {
            return 'Kupon belum berlaku.';
        }

        if ($this->end_at !== null && $this->end_at->lt($now)) {
            return 'Masa berlaku kupon sudah habis.';
        }

        if ($this->max_usage > 0 && $this->used_count >= $this->max_usage) {
            return 'Kuota kupon sudah habis.';
        }

        if ($this->min_purchase > $purchaseAmount) {
            return 'Minimal pembelian untuk kupon ini Rp '.number_format((float) $this->min_purchase, 0, ',', '.').'.';
        }

        return null;
    }

    public function calculateDiscount(float $amount): float
    {
        $discount = $this->discount_type === 'percentage'
            ? $amount * $this->discount_value / 100
            : $this->discount_value;

        return round(min($discount, $amount), 2);
    }
}
