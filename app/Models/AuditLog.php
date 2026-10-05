<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Log;

class AuditLog extends Model
{
    use HasFactory;

    public const UPDATED_AT = null;

    protected $fillable = [
        'admin_id',
        'action',
        'model',
        'model_id',
        'old_values',
        'new_values',
        'ip_address',
    ];

    protected function casts(): array
    {
        return [
            'old_values' => 'array',
            'new_values' => 'array',
            'model_id' => 'integer',
        ];
    }

    public function admin(): BelongsTo
    {
        return $this->belongsTo(Admin::class);
    }

    /**
     * Catat aksi admin ke audit log. Fail-safe: kegagalan pencatatan
     * (mis. kolom terlalu besar) hanya menghasilkan log error, tidak boleh
     * menggagalkan aksi utama.
     *
     * @param  array<string, mixed>|null  $old
     * @param  array<string, mixed>|null  $new
     */
    public static function record(?Admin $admin, string $action, string $model, ?int $modelId = null, ?array $old = null, ?array $new = null): void
    {
        try {
            static::create([
                'admin_id' => $admin?->id,
                'action' => $action,
                'model' => $model,
                'model_id' => $modelId,
                'old_values' => $old,
                'new_values' => $new,
                'ip_address' => request()?->ip(),
            ]);
        } catch (\Throwable $e) {
            Log::error('Gagal mencatat audit log: '.$e->getMessage());
        }
    }
}
