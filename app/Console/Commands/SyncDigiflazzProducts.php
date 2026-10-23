<?php

namespace App\Console\Commands;

use App\Models\ProductItem;
use App\Services\Notification\TelegramAlertService;
use App\Services\Supplier\DigiflazzClient;
use App\Services\Supplier\DigiflazzPricelistRepository;
use App\Services\Supplier\PricelistSnapshot;
use App\Services\Supplier\PricelistUnavailableException;
use App\Support\DigiflazzCatalog;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Sinkronisasi ketersediaan SKU + harga jual dari DigiFlazz.
 *
 * Prinsip fail-closed yang dipegang command ini:
 *  1. Data yang lebih tua dari `supplier.digiflazz.pricelist.max_age_minutes`
 *     TIDAK dipakai untuk mengubah katalog. Cache lama tidak boleh menaikkan
 *     (atau menonaktifkan) produk, karena hasilnya katalog yang menampilkan SKU
 *     yang sebenarnya sudah mati — atau sebaliknya.
 *     Cache hasil fallback (RC 83 / API error) juga bukan bukti ketersediaan,
 *     sekalipun umurnya masih di bawah batas; hanya `--force-cache` eksplisit
 *     yang boleh memakainya.
 *  2. Perubahan nonaktifkan massal (indikasi pricelist terpotong/API rusak)
 *     ditahan dan dieskalasi ke admin, bukan diterapkan.
 *  3. Semua penulisan katalog terjadi dalam satu transaksi.
 */
class SyncDigiflazzProducts extends Command
{
    protected $signature = 'supplier:sync-digiflazz
        {--force-cache : Jangan panggil API, pakai cache lokal (tetap tunduk pada batas umur)}
        {--max-age= : Batas umur data dalam menit sebelum sinkronisasi dibatalkan}
        {--allow-mass-deactivation : Lewati pengaman nonaktifkan massal (gunakan sadar risiko)}
        {--dry-run : Tampilkan rencana perubahan tanpa menulis ke katalog}';

    protected $description = 'Sinkronisasi ketersediaan SKU & update harga jual (profit 7%) dari DigiFlazz, fail-closed bila data basi';

    public function handle(
        DigiflazzClient $client,
        DigiflazzPricelistRepository $repository,
        TelegramAlertService $telegram,
    ): int {
        $maxAge = $this->maxAgeMinutes($repository);
        $this->info("Memulai sinkronisasi produk DigiFlazz (batas umur data: {$maxAge} menit)...");

        try {
            $snapshot = $repository->fetch($client, (bool) $this->option('force-cache'));
        } catch (PricelistUnavailableException $e) {
            return $this->abort($telegram, 'Pricelist tidak tersedia', $e->getMessage(), $repository);
        }

        $this->line(sprintf(
            'Sumber data: %s | umur: %s | item: %d%s',
            $snapshot->source,
            $snapshot->ageLabel(),
            $snapshot->count(),
            $snapshot->legacyCache ? ' | cache format lama' : '',
        ));

        // Cache format lama (array polos tanpa `fetched_at`) tidak punya waktu
        // ambil yang bisa dipercaya: perlakukan seperti data basi.
        if ($snapshot->legacyCache || ! $snapshot->isFresh($maxAge)) {
            $detail = $snapshot->legacyCache
                ? 'Cache berformat lama tanpa metadata fetched_at dianggap BASI.'
                : "Umur data {$snapshot->ageLabel()} (sumber: {$snapshot->source}) melewati batas {$maxAge} menit.";
            $detail .= ($snapshot->fallbackReason ? " Penyebab: {$snapshot->fallbackReason}" : '')
                .' Katalog TIDAK diubah supaya status aktif tidak ditentukan oleh data lama.';

            return $this->abort($telegram, 'Data pricelist BASI — sinkronisasi dibatalkan', $detail, $repository);
        }

        // RC 83 / API error membuat data hanya tersedia dari cache. Itu bukan
        // bukti ketersediaan walau umurnya masih segar, jadi katalog tidak diubah.
        if ($snapshot->isFromCache() && ! $this->option('force-cache')) {
            $detail = "Data berasal dari cache (umur {$snapshot->ageLabel()}), bukan pengambilan live."
                .($snapshot->fallbackReason ? " Penyebab: {$snapshot->fallbackReason}" : '')
                .' Katalog TIDAK diubah.';

            return $this->abort($telegram, 'Pricelist hanya tersedia dari cache — sinkronisasi dibatalkan', $detail, $repository);
        }

        $plan = $this->buildPlan($snapshot);

        $activeNow = $plan['active_now'];
        // Item seller yang diblokir wajib nonaktif walau jumlahnya besar, jadi
        // pengaman nonaktifkan massal hanya menghitung item non-blokir.
        $plannedDeactivations = count(array_filter($plan['deactivations'], fn (array $d) => ! $d['blocked']));
        $limit = $this->deactivationLimit($activeNow);

        if ($plannedDeactivations > $limit && ! $this->option('allow-mass-deactivation')) {
            $detail = "Rencana menonaktifkan {$plannedDeactivations} dari {$activeNow} item aktif (batas aman {$limit})."
                .' Ini biasanya berarti pricelist terpotong atau API mengembalikan data tidak lengkap.'
                .' Tidak ada perubahan yang diterapkan.';

            return $this->abort($telegram, 'Pengaman nonaktifkan massal aktif', $detail, $repository);
        }

        if ($this->option('dry-run')) {
            return $this->reportDryRun($snapshot, $plan, $maxAge);
        }

        $applied = $this->applyPlan($plan);

        $summary = "🔄 <b>[SINKRONISASI DIGIFLAZZ SELESAI]</b>\n"
            ."• Sumber data: {$snapshot->source} (umur {$snapshot->ageLabel()})\n"
            ."• Item Aktif & Diperbarui: {$applied['activated']}\n"
            ."• Item Dinonaktifkan (Keamanan): {$applied['deactivated']}\n"
            ."• Harga Modal/Jual Berubah: {$applied['price_updated']}\n"
            ."• Anomali Data Dilewati: {$plan['anomalies']}\n"
            ."• Total Item Dipindai: {$plan['total']}";

        $this->info($summary);
        $this->line('Cache pricelist: '.$repository->cachePath());

        $repository->recordSuccess($snapshot, [
            'activated' => $applied['activated'],
            'deactivated' => $applied['deactivated'],
            'price_updated' => $applied['price_updated'],
            'items_scanned' => $plan['total'],
        ]);

        $changes = $applied['changes'];
        $shouldNotify = ! empty($changes) || $snapshot->isFromCache();

        if ($shouldNotify) {
            $report = $summary;
            if ($snapshot->isFromCache()) {
                $report .= "\n⚠️ <i>Data dari cache (umur {$snapshot->ageLabel()}, batas {$maxAge} menit)."
                    .($snapshot->fallbackReason ? ' Penyebab: '.$snapshot->fallbackReason : '').'</i>';
            }
            if (! empty($changes)) {
                $report .= "\n\n<b>Detail Perubahan:</b>\n".implode("\n", array_slice($changes, 0, 15));
                if (count($changes) > 15) {
                    $report .= "\n<i>...dan ".(count($changes) - 15).' perubahan lainnya.</i>';
                }
            }
            try {
                $telegram->send($report);
            } catch (\Throwable $e) {
                Log::warning('Gagal kirim notif telegram sync: '.$e->getMessage());
            }
        }

        Log::info('supplier:sync-digiflazz selesai', [
            'source' => $snapshot->source,
            'data_age_minutes' => $snapshot->ageMinutes(),
            'pricelist_items' => $snapshot->count(),
            'scanned' => $plan['total'],
            'activated' => $applied['activated'],
            'deactivated' => $applied['deactivated'],
            'price_updated' => $applied['price_updated'],
            'anomalies' => $plan['anomalies'],
        ]);

        return Command::SUCCESS;
    }

    /**
     * Bandingkan pricelist dengan item katalog TANPA menulis apa pun.
     *
     * @return array{
     *     total: int,
     *     active_now: int,
     *     anomalies: int,
     *     updates: array<int, array{id: int, data: array<string, mixed>, changes: array<int, string>}>,
     *     deactivations: array<int, array{id: int, blocked: bool, message: string}>
     * }
     */
    private function buildPlan(PricelistSnapshot $snapshot): array
    {
        $bySku = [];
        foreach ($snapshot->items as $row) {
            if (! is_array($row) || empty($row['buyer_sku_code'])) {
                continue;
            }
            $bySku[(string) $row['buyer_sku_code']] = $row;
        }

        $items = ProductItem::whereHas('product', fn ($q) => $q->where('supplier_type', 'digiflazz'))
            ->with('product')
            ->get();

        $updates = [];
        $deactivations = [];
        $anomalies = 0;
        $activeNow = 0;
        $plannedActivations = 0;
        $plannedPriceChanges = 0;

        foreach ($items as $item) {
            $isActive = (bool) $item->is_active;
            if ($isActive) {
                $activeNow++;
            }

            $sku = $item->supplier_item_code;
            $digi = $sku ? ($bySku[$sku] ?? null) : null;

            $isAvailable = DigiflazzCatalog::isAvailableRow($digi);

            if (! $isAvailable) {
                if ($isActive) {
                    $reason = match (true) {
                        $digi === null => 'SKU Belum Dipilihkan Seller di DigiFlazz',
                        DigiflazzCatalog::sellerBlocked($digi['seller_name'] ?? null) => 'Seller Diblokir',
                        default => 'Seller Non-Aktif / Gangguan',
                    };

                    $deactivations[] = [
                        'id' => (int) $item->id,
                        'blocked' => $digi !== null && DigiflazzCatalog::sellerBlocked($digi['seller_name'] ?? null),
                        'message' => "🔴 [DINONAKTIFKAN] {$item->product->name} - {$item->name} (SKU: {$sku}) -> {$reason}",
                    ];
                }

                continue;
            }

            $digiPrice = (float) ($digi['price'] ?? 0);

            // Harga 0/negatif bukan harga valid; jangan pernah menulis harga jual 0
            // ke katalog (produk jadi gratis). Lewati dan hitung sebagai anomali.
            if ($digiPrice <= 0) {
                $anomalies++;
                Log::warning('Pricelist DigiFlazz: harga tidak valid, item dilewati.', [
                    'sku' => $sku,
                    'item_id' => $item->id,
                    'price' => $digi['price'] ?? null,
                ]);

                continue;
            }

            $oldBuy = (float) $item->buy_price;
            $oldSell = (float) $item->sell_price;

            // Harga jual: profit 7%, dibulatkan ke atas ke kelipatan 100 rupiah.
            $newSell = ceil(($digiPrice * 1.07) / 100) * 100;

            $changes = [];
            if (! $isActive) {
                $changes[] = "🟢 [AKTIF KEMBALI] {$item->product->name} - {$item->name} (SKU: {$sku})";
            }
            if ($digiPrice != $oldBuy || $newSell != $oldSell) {
                $changes[] = "💰 [UPDATE HARGA] {$item->product->name} - {$item->name}: Modal Rp {$oldBuy} -> Rp {$digiPrice} | Jual Rp {$oldSell} -> Rp {$newSell}";
            }

            $updates[] = [
                'id' => (int) $item->id,
                'data' => [
                    'buy_price' => number_format($digiPrice, 2, '.', ''),
                    'sell_price' => number_format($newSell, 2, '.', ''),
                    'is_active' => true,
                ],
                'changes' => $changes,
            ];
        }

        return [
            'total' => $items->count(),
            'active_now' => $activeNow,
            'anomalies' => $anomalies,
            'updates' => $updates,
            'deactivations' => $deactivations,
        ];
    }

    /**
     * @param  array{total: int, active_now: int, anomalies: int, updates: array<int, array{id: int, data: array<string, mixed>, changes: array<int, string>}>, deactivations: array<int, array{id: int, message: string}>}  $plan
     * @return array{activated: int, deactivated: int, price_updated: int, changes: array<int, string>}
     */
    private function applyPlan(array $plan): array
    {
        $activated = 0;
        $deactivated = 0;
        $priceUpdated = 0;
        $changes = [];

        DB::transaction(function () use ($plan, &$activated, &$deactivated, &$priceUpdated, &$changes): void {
            foreach ($plan['updates'] as $update) {
                $item = ProductItem::find($update['id']);
                if (! $item) {
                    continue;
                }

                if (! $item->is_active) {
                    $activated++;
                }
                if ((float) $item->buy_price != (float) $update['data']['buy_price']
                    || (float) $item->sell_price != (float) $update['data']['sell_price']) {
                    $priceUpdated++;
                }

                $item->update($update['data']);
                array_push($changes, ...$update['changes']);
            }

            foreach ($plan['deactivations'] as $deactivation) {
                $item = ProductItem::find($deactivation['id']);
                if (! $item || ! $item->is_active) {
                    continue;
                }

                $item->update(['is_active' => false]);
                $deactivated++;
                $changes[] = $deactivation['message'];
            }
        });

        return [
            'activated' => $activated,
            'deactivated' => $deactivated,
            'price_updated' => $priceUpdated,
            'changes' => $changes,
        ];
    }

    /**
     * Tampilkan rencana perubahan tanpa menyentuh katalog.
     *
     * @param  array{total: int, active_now: int, anomalies: int, updates: array<int, array{id: int, data: array<string, mixed>, changes: array<int, string>}>, deactivations: array<int, array{id: int, blocked: bool, message: string}>}  $plan
     */
    private function reportDryRun(PricelistSnapshot $snapshot, array $plan, int $maxAge): int
    {
        $this->info('DRY RUN — tidak ada perubahan yang ditulis ke katalog.');
        $this->line(sprintf(
            'Sumber: %s | umur: %s (batas %d menit) | dipindai: %d | akan diaktifkan/diperbarui: %d | akan dinonaktifkan: %d | anomali: %d',
            $snapshot->source,
            $snapshot->ageLabel(),
            $maxAge,
            $plan['total'],
            count($plan['updates']),
            count($plan['deactivations']),
            $plan['anomalies'],
        ));

        $changes = array_column($plan['deactivations'], 'message');
        foreach ($plan['updates'] as $update) {
            array_push($changes, ...$update['changes']);
        }

        foreach (array_slice($changes, 0, 20) as $change) {
            $this->line($change);
        }

        return Command::SUCCESS;
    }

    private function maxAgeMinutes(DigiflazzPricelistRepository $repository): int
    {
        $option = $this->option('max-age');
        if ($option !== null && $option !== '' && is_numeric($option)) {
            return max(0, (int) $option);
        }

        return $repository->maxAgeMinutes();
    }

    /**
     * Batas jumlah item yang boleh dinonaktifkan dalam satu kali sync:
     * maksimum dari lantai absolut dan rasio terhadap item aktif.
     */
    private function deactivationLimit(int $activeNow): int
    {
        $floor = (int) config('supplier.digiflazz.pricelist.max_deactivation_floor', 5);
        $ratio = (float) config('supplier.digiflazz.pricelist.max_deactivation_ratio', 0.25);

        return max($floor, (int) ceil($activeNow * $ratio));
    }

    private function abort(
        TelegramAlertService $telegram,
        string $title,
        string $detail,
        DigiflazzPricelistRepository $repository,
    ): int {
        $hint = $this->lastSuccessHint($repository);

        $this->error($title);
        $this->line($detail);
        if ($hint !== '') {
            $this->line($hint);
        }

        Log::error('supplier:sync-digiflazz dibatalkan: '.$title, ['detail' => $detail, 'hint' => $hint]);

        try {
            $telegram->send("⛔ <b>[SYNC DIGIFLAZZ DIBATALKAN]</b>\n{$title}\n{$detail}".($hint !== '' ? "\n{$hint}" : ''));
        } catch (\Throwable $e) {
            Log::warning('Gagal kirim notif telegram abort sync: '.$e->getMessage());
        }

        return Command::FAILURE;
    }

    private function lastSuccessHint(DigiflazzPricelistRepository $repository): string
    {
        $last = $repository->lastSuccess();

        if (! $last || empty($last['synced_at'])) {
            return 'Belum ada sinkronisasi sukses yang tercatat.';
        }

        return 'Sinkronisasi sukses terakhir: '.$last['synced_at'].' (sumber '.($last['source'] ?? '?').').';
    }
}
