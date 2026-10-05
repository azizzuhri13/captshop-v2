<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PaymentChannel extends Model
{
    use HasFactory;

    protected $fillable = [
        'gateway',
        'code',
        'name',
        'group',
        'icon',
        'flat_fee',
        'percent_fee',
        'is_active',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'flat_fee' => 'decimal:2',
            'percent_fee' => 'decimal:2',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function calculateFee(float $amount): float
    {
        return round($this->flat_fee + ($amount * $this->percent_fee / 100), 2);
    }
}
