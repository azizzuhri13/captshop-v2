<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * 10 baris netflix_partner_orders ditulis saat APP_TIMEZONE masih UTC, sementara
 * pembaca sekarang menafsirkan kolom datetime sebagai WIB. Wall clock tersimpan
 * digeser +7 jam untuk tepat 10 order hasil audit; status, alokasi akun, payment
 * dan entitlement tidak disentuh. Idempotensi bergantung pada ledger migrasi
 * Laravel, bukan cutoff waktu -- baris di luar daftar ini tidak pernah ikut.
 *
 * shortcut: datetime(col, '+7 hours') adalah sintaks SQLite; ganti ke
 * DATE_ADD/INTERVAL kalau database produksi pindah dari SQLite.
 */
return new class extends Migration
{
    private const ORDER_IDS = [
        'MUR-20261019-EDYG86',
        'MUR-20261019-WI3WVE',
        'MUR-20261019-VQZA18',
        'MUR-20261019-U9TJ16',
        'MUR-20261020-BSNEMJ',
        'MUR-20261020-BNUXWO',
        'MUR-20261020-6C8PDR',
        'MUR-20261020-46S3KB',
        'MUR-20261021-ILR5RY',
        'MUR-20261021-WSKEX7',
    ];

    private const TIME_COLUMNS = ['verified_at', 'claimed_at', 'reserved_until', 'cancelled_at', 'created_at', 'updated_at'];

    public function up(): void
    {
        $this->shift('+7 hours');
    }

    public function down(): void
    {
        $this->shift('-7 hours');
    }

    private function shift(string $modifier): void
    {
        $assignments = array_map(
            fn (string $column) => "{$column} = CASE WHEN {$column} IS NULL THEN NULL ELSE datetime({$column}, '{$modifier}') END",
            self::TIME_COLUMNS
        );

        $placeholders = implode(',', array_fill(0, count(self::ORDER_IDS), '?'));

        DB::update(
            'UPDATE netflix_partner_orders SET '.implode(', ', $assignments)." WHERE order_id IN ({$placeholders})",
            self::ORDER_IDS
        );
    }
};
