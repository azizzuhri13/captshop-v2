<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOneThrough;

class Transaction extends Model
{
    use HasFactory;

    protected $fillable = [
        'invoice_id',
        'product_item_id',
        'customer_data',
        'customer_phone',
        'customer_email',
        'amount',
        'fee',
        'total',
        'profit',
        'coupon_code',
        'coupon_discount',
        'payment_method',
        'payment_channel',
        'payment_ref',
        'payment_url',
        'status',
        'supplier_ref',
        'supplier_sn',
        'supplier_response',
        'paid_at',
        'processed_at',
        'completed_at',
        'expired_at',
    ];

    protected function casts(): array
    {
        return [
            'customer_data' => 'array',
            'amount' => 'decimal:2',
            'fee' => 'decimal:2',
            'total' => 'decimal:2',
            'profit' => 'decimal:2',
            'coupon_discount' => 'decimal:2',
            'paid_at' => 'datetime',
            'processed_at' => 'datetime',
            'completed_at' => 'datetime',
            'expired_at' => 'datetime',
        ];
    }

    public function productItem(): BelongsTo
    {
        return $this->belongsTo(ProductItem::class);
    }

    public function product(): HasOneThrough
    {
        return $this->hasOneThrough(
            Product::class,
            ProductItem::class,
            'id',
            'id',
            'product_item_id',
            'product_id'
        );
    }

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    public function statusLabel(): string
    {
        return match ($this->status) {
            'pending' => 'Menunggu Pembayaran',
            'paid' => 'Dibayar',
            'processing' => 'Diproses',
            'completed' => 'Berhasil',
            'failed' => 'Gagal',
            'expired' => 'Kedaluwarsa',
            'refunded' => 'Direfund',
            default => ucfirst($this->status),
        };
    }

    public function statusBadgeClass(): string
    {
        return match ($this->status) {
            'completed', 'paid' => 'bg-emerald-500/15 text-emerald-400',
            'processing' => 'bg-sky-500/15 text-sky-400',
            'failed', 'refunded' => 'bg-red-500/15 text-red-400',
            'expired' => 'bg-slate-500/15 text-slate-400',
            default => 'bg-amber-500/15 text-amber-400',
        };
    }
}
