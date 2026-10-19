<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Article extends Model
{
    use HasFactory;

    protected $fillable = [
        'title',
        'slug',
        'thumbnail',
        'excerpt',
        'body',
        'author_id',
        'category',
        'tags',
        'related_product_id',
        'views_count',
        'reading_time_minutes',
        'is_published',
        'published_at',
    ];

    protected function casts(): array
    {
        return [
            'tags' => 'array',
            'is_published' => 'boolean',
            'published_at' => 'datetime',
            'views_count' => 'integer',
            'reading_time_minutes' => 'integer',
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

    public function author(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'author_id');
    }

    public function relatedProduct(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'related_product_id');
    }

    public function thumbnailUrl(): ?string
    {
        if (! $this->thumbnail) {
            return null;
        }

        if (str_starts_with($this->thumbnail, 'http://') || str_starts_with($this->thumbnail, 'https://')) {
            return $this->thumbnail;
        }

        return asset('storage/' . ltrim($this->thumbnail, '/'));
    }
}
