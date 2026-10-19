<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NetflixAccount extends Model
{
    protected $fillable = [
        'email',
        'password',
        'profile_name',
        'pin',
        'status',
        'transaction_id',
        'sold_at',
        'notes',
    ];

    protected $casts = [
        'sold_at' => 'datetime',
    ];

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }
}
