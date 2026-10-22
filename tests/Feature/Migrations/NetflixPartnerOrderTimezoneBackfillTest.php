<?php

namespace Tests\Feature\Migrations;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Regresi backfill zona waktu netflix_partner_orders.
 *
 * 10 baris dibuat sebelum APP_TIMEZONE diubah ke Asia/Jakarta: kolom datetime
 * era itu menyimpan wall clock UTC, sementara pembaca sekarang menafsirkan
 * nilai tersimpan sebagai WIB. Koreksinya = +7 jam pada 10 order_id yang
 * diaudit, bukan cutoff waktu dan bukan perubahan sisi baca.
 */
class NetflixPartnerOrderTimezoneBackfillTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION = '2026_10_22_120000_backfill_netflix_partner_order_wib_timestamps.php';

    private const LISTED = [
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

    /** Batas scope: baris cancelled (dikecualikan audit) + baris pasca-switch (sudah WIB). */
    private const OUT_OF_SCOPE = [
        'MUR-20261020-5M0YFB' => ['status' => 'cancelled', 'verified_at' => null, 'claimed_at' => null, 'cancelled_at' => '2026-10-20 12:50:24', 'start_date' => null, 'end_date' => null, 'warranty_until' => null],
        'MUR-20261022-BROL2I' => ['status' => 'completed', 'verified_at' => '2026-10-22 13:59:27', 'claimed_at' => '2026-10-22 13:59:30', 'cancelled_at' => null],
    ];

    private const TIME_COLUMNS = ['verified_at', 'claimed_at', 'reserved_until', 'cancelled_at', 'created_at', 'updated_at'];

    private function migration(): object
    {
        $path = database_path('migrations/'.self::MIGRATION);
        $this->assertFileExists($path, 'migrasi backfill zona waktu belum ada');

        return require $path;
    }

    private function seedRow(string $orderId, array $overrides = []): void
    {
        DB::table('netflix_partner_orders')->insert(array_merge([
            'order_id' => $orderId,
            'reservation_id' => 'res-'.$orderId,
            'payload_hash' => str_repeat('a', 64),
            'package_id' => '1p1u_1m',
            'buyer_reference' => '730001',
            'buyer_username' => 'buyer_tele',
            'ttl_minutes' => 30,
            'status' => 'completed',
            'netflix_account_id' => null,
            'price' => 30000,
            'currency' => 'IDR',
            'payment_reference' => 'PAY-'.$orderId,
            'payment_amount' => 30000,
            'payment_currency' => 'IDR',
            'verified_at' => '2026-10-21 13:20:59',
            'reserved_until' => '2026-10-21 13:50:09',
            'claimed_at' => '2026-10-21 13:21:01',
            'cancelled_at' => null,
            'start_date' => '2026-10-21',
            'end_date' => '2026-11-20',
            'warranty_until' => '2026-11-20',
            'otp_token' => 'tok-'.$orderId,
            'created_at' => '2026-10-21 13:20:09',
            'updated_at' => '2026-10-21 13:21:01',
        ], $overrides));
    }

    /** @return array<string, array<string, mixed>> */
    private function snapshot(): array
    {
        return DB::table('netflix_partner_orders')->orderBy('id')->get()
            ->mapWithKeys(fn ($row) => [$row->order_id => (array) $row])
            ->all();
    }

    private function plusSevenHours(string $value): string
    {
        return (new \DateTimeImmutable($value, new \DateTimeZone('UTC')))
            ->modify('+7 hours')
            ->format('Y-m-d H:i:s');
    }

    public function test_backfill_shifts_exactly_the_ten_listed_rows_by_seven_hours(): void
    {
        foreach (self::LISTED as $orderId) {
            $this->seedRow($orderId);
        }
        foreach (self::OUT_OF_SCOPE as $orderId => $overrides) {
            $this->seedRow($orderId, $overrides);
        }

        $before = $this->snapshot();

        ($this->migration())->up();

        $after = $this->snapshot();

        foreach (self::LISTED as $orderId) {
            foreach (self::TIME_COLUMNS as $column) {
                $expected = $before[$orderId][$column] === null
                    ? null
                    : $this->plusSevenHours($before[$orderId][$column]);

                $this->assertSame($expected, $after[$orderId][$column], "{$orderId}.{$column} tidak bergeser +7 jam");
            }

            // Kolom non-waktu (status, alokasi akun, otp, payment, entitlement) harus byte-identik.
            $this->assertSame(
                array_diff_key($before[$orderId], array_flip(self::TIME_COLUMNS)),
                array_diff_key($after[$orderId], array_flip(self::TIME_COLUMNS)),
                "kolom non-waktu {$orderId} berubah"
            );
        }

        foreach (array_keys(self::OUT_OF_SCOPE) as $orderId) {
            $this->assertSame($before[$orderId], $after[$orderId], "baris di luar scope {$orderId} berubah");
        }

        // down() simetris: kembali byte-identik dengan kondisi sebelum backfill.
        ($this->migration())->down();
        $this->assertSame($before, $this->snapshot(), 'down() tidak mengembalikan kondisi awal');
    }

    public function test_migration_ledger_prevents_a_second_shift(): void
    {
        foreach (self::LISTED as $orderId) {
            $this->seedRow($orderId);
        }

        $before = $this->snapshot();

        // Baris sudah ditulis setelah migrasi tercatat: ledger, bukan cutoff waktu,
        // yang menjamin backfill tidak jalan dua kali.
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);

        $this->assertSame($before, $this->snapshot(), 'backfill jalan dua kali');
    }
}
