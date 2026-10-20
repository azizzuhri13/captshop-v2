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

    /**
     * Cek apakah produk termasuk kategori akun premium / streaming.
     */
    public function isPremiumAccount(): bool
    {
        return ($this->category?->slug === 'apps-premium')
            || in_array($this->slug, ['netflix-premium', 'disney-plus', 'canva-pro'], true);
    }

    /**
     * Hitung total ketersediaan stok produk akun premium.
     */
    public function getStock(): ?int
    {
        if ($this->slug === 'netflix-premium') {
            return \App\Models\NetflixAccount::where('status', 'ready')->count();
        }

        if ($this->slug === 'disney-plus') {
            return \App\Models\DisneyAccount::where('status', 'ready')->count();
        }

        if ($this->slug === 'canva-pro') {
            return \App\Models\CanvaAccount::where('status', 'ready')->count();
        }

        if ($this->isPremiumAccount()) {
            $trackedItems = $this->items->filter(fn ($item) => $item->isStockTracked());
            if ($trackedItems->isNotEmpty()) {
                return (int) $trackedItems->sum(fn ($item) => (int) $item->getStock());
            }
        }

        return null;
    }

    /**
     * Cek apakah stok produk premium habis.
     */
    public function isOutOfStock(): bool
    {
        if (! $this->isPremiumAccount()) {
            return false;
        }

        $stock = $this->getStock();

        return $stock !== null && $stock <= 0;
    }
}
