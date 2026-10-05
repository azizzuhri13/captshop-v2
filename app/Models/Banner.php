<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Banner extends Model
{
    use HasFactory;

    protected $fillable = [
        'title',
        'image',
        'url',
        'sort_order',
        'start_at',
        'end_at',
        'is_active',
        'type',
    ];

    protected function casts(): array
    {
        return [
            'start_at' => 'datetime',
            'end_at' => 'datetime',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function isCurrentlyVisible(): bool
    {
        if (! $this->is_active) {
            return false;
        }

        $now = now();

        return ($this->start_at === null || $this->start_at->lte($now))
            && ($this->end_at === null || $this->end_at->gte($now));
    }

    /**
     * URL gambar yang bisa diakses browser. Menggunakan asset() agar
     * mengikuti host/port request (bukan APP_URL statis) — penting saat
     * diakses via php artisan serve (port 8000, dll).
     */
    public function imageUrl(): ?string
    {
        if (! $this->image) {
            return null;
        }

        if (str_starts_with($this->image, 'http')) {
            return $this->image;
        }

        return asset('storage/'.$this->image);
    }
}
