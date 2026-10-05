<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProductItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'product_id',
        'name',
        'group',
        'supplier_item_code',
        'buy_price',
        'sell_price',
        'flash_sale_price',
        'flash_sale_start',
        'flash_sale_end',
        'is_active',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'buy_price' => 'decimal:2',
            'sell_price' => 'decimal:2',
            'flash_sale_price' => 'decimal:2',
            'flash_sale_start' => 'datetime',
            'flash_sale_end' => 'datetime',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    public function isFlashSaleActive(): bool
    {
        if ($this->flash_sale_price === null) {
            return false;
        }

        $now = now();

        return ($this->flash_sale_start === null || $this->flash_sale_start->lte($now))
            && ($this->flash_sale_end === null || $this->flash_sale_end->gte($now));
    }

    public function effectivePrice(): string
    {
        return $this->isFlashSaleActive() ? $this->flash_sale_price : $this->sell_price;
    }
}
