<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Product extends Model
{
    use HasFactory;

    protected $fillable = [
        'category_id',
        'name',
        'slug',
        'code',
        'icon',
        'banner',
        'description',
        'instruction',
        'input_fields',
        'supplier_type',
        'supplier_code',
        'is_popular',
        'is_active',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'input_fields' => 'array',
            'is_popular' => 'boolean',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function setSlugAttribute($value): void
    {
        $clean = trim((string) $value);
        if (str_contains($clean, '/') || str_starts_with($clean, 'http')) {
            $path = parse_url($clean, PHP_URL_PATH) ?? $clean;
            $clean = basename(rtrim((string) $path, '/'));
        }
        $this->attributes['slug'] = \Illuminate\Support\Str::slug($clean);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(ProductItem::class);
    }

    /**
     * Resolve path/URL gambar ke URL yang bisa diakses browser.
     * Menggunakan asset() agar mengikuti host/port request yang aktif.
     */
    protected function resolveImageUrl(?string $value): ?string
    {
        if (! $value) {
            return null;
        }

        if (str_starts_with($value, 'http')) {
            return $value;
        }

        return asset('storage/'.$value);
    }

    public function iconUrl(): ?string
    {
        return $this->resolveImageUrl($this->icon);
    }

    public function bannerUrl(): ?string
    {
        return $this->resolveImageUrl($this->banner);
    }
}
