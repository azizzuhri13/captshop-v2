<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Category extends Model
{
    use HasFactory;

    protected $fillable = [
        'parent_id',
        'name',
        'slug',
        'icon',
        'banner',
        'description',
        'sort_order',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
            'is_active' => 'boolean',
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

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    /**
     * URL ikon jika berupa path storage/URL http; null jika emoji/teks.
     */
    public function iconUrl(): ?string
    {
        if (! $this->icon) {
            return null;
        }

        if (str_starts_with($this->icon, 'http')) {
            return $this->icon;
        }

        if (preg_match('#^[\w\-/]+\.(png|jpe?g|webp|gif|svg)$#i', $this->icon)) {
            return asset('storage/'.$this->icon);
        }

        return null;
    }
}
