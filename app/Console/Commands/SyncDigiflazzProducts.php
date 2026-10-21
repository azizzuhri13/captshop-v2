<?php

namespace App\Console\Commands;

use App\Models\ProductItem;
use App\Services\Notification\TelegramAlertService;
use App\Services\Supplier\DigiflazzClient;
use App\Support\Credentials;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

class SyncDigiflazzProducts extends Command
{
    protected $signature = 'supplier:sync-digiflazz {--force-cache : Gunakan cache lokal jika API rate-limited}';

    protected $description = 'Sinkronisasi ketersediaan SKU & update harga jual (profit 7%) otomatis dari DigiFlazz';

    public function handle(DigiflazzClient $client, TelegramAlertService $telegram): int
    {
        $this->info('Memulai sinkronisasi produk DigiFlazz...');

        $pricelist = $this->fetchPricelist($client);

        if (empty($pricelist)) {
            $this->error('Gagal mengambil daftar harga DigiFlazz (Rate limit atau koneksi gagal).');
            return Command::FAILURE;
        }

        $activeDigi = collect($pricelist)->keyBy('buyer_sku_code');

        $items = ProductItem::whereHas('product', fn ($q) => $q->where('supplier_type', 'digiflazz'))
            ->with('product')
            ->get();

        $activated = 0;
        $deactivated = 0;
        $priceUpdated = 0;
        $changes = [];

        foreach ($items as $item) {
            $sku = $item->supplier_item_code;
            $digi = $sku ? $activeDigi->get($sku) : null;

            $isAvailable = $digi !== null
                && ! empty($digi['buyer_product_status'])
                && ! empty($digi['seller_product_status']);

            if ($isAvailable) {
                $digiPrice = (float) $digi['price'];
                $oldBuy = (float) $item->buy_price;
                $oldSell = (float) $item->sell_price;

                // Hitung harga jual dengan profit 7%, dibulatkan ke atas ke kelipatan 100 rupiah terdekat
                $newSell = ceil(($digiPrice * 1.07) / 100) * 100;

                $dataToUpdate = [
                    'buy_price' => number_format($digiPrice, 2, '.', ''),
                    'sell_price' => number_format($newSell, 2, '.', ''),
                    'is_active' => true,
                ];

                if (! $item->is_active) {
                    $activated++;
                    $changes[] = "🟢 [AKTIF KEMBALI] {$item->product->name} - {$item->name} (SKU: {$sku})";
                }

                if ($digiPrice != $oldBuy || $newSell != $oldSell) {
                    $priceUpdated++;
                    $changes[] = "💰 [UPDATE HARGA] {$item->product->name} - {$item->name}: Modal Rp {$oldBuy} -> Rp {$digiPrice} | Jual Rp {$oldSell} -> Rp {$newSell}";
                }

                $item->update($dataToUpdate);
            } else {
                // Nonaktifkan item jika SKU tidak ditemukan di DigiFlazz atau seller gangguan
                if ($item->is_active) {
                    $item->update(['is_active' => false]);
                    $deactivated++;
                    $reason = $digi ? 'Seller Non-Aktif / Gangguan' : 'SKU Belum Dipilihkan Seller di DigiFlazz';
                    $changes[] = "🔴 [DINONAKTIFKAN] {$item->product->name} - {$item->name} (SKU: {$sku}) -> {$reason}";
                }
            }
        }

        $summary = "🔄 <b>[SINKRONISASI DIGIFLAZZ SELESAI]</b>\n"
            . "• Item Aktif & Diperbarui: {$activated}\n"
            . "• Item Dinonaktifkan (Keamanan): {$deactivated}\n"
            . "• Harga Modal/Jual Berubah: {$priceUpdated}\n"
            . "• Total Item Dipindai: {$items->count()}";

        $this->info($summary);

        if (! empty($changes)) {
            $report = $summary . "\n\n<b>Detail Perubahan:</b>\n" . implode("\n", array_slice($changes, 0, 15));
            if (count($changes) > 15) {
                $report .= "\n<i>...dan " . (count($changes) - 15) . " perubahan lainnya.</i>";
            }
            try {
                $telegram->send($report);
            } catch (\Throwable $e) {
                Log::warning('Gagal kirim notif telegram sync: ' . $e->getMessage());
            }
        }

        return Command::SUCCESS;
    }

    protected function fetchPricelist(DigiflazzClient $client): ?array
    {
        $cacheFile = '/root/.hermes/cache/scratch/digiflazz_list.json';

        $username = (string) Credentials::get('digiflazz_username', config('supplier.digiflazz.username'));
        $apiKey = (string) Credentials::get('digiflazz_api_key', config('supplier.digiflazz.api_key'));
        $sign = md5($username . $apiKey . 'pricelist');

        try {
            $res = $client->post('/price-list', [
                'cmd' => 'prepaid',
                'username' => $username,
                'sign' => $sign,
            ]);

            if (isset($res['data']['rc']) && $res['data']['rc'] == '83') {
                $this->warn('Pricelist DigiFlazz terkena rate limit (RC 83).');
                if (File::exists($cacheFile)) {
                    $this->info('Menggunakan cache data harga terakhir.');
                    return json_decode(File::get($cacheFile), true);
                }
                return null;
            }

            if (! empty($res['data']) && is_array($res['data'])) {
                File::put($cacheFile, json_encode($res['data']));
                return $res['data'];
            }
        } catch (\Throwable $e) {
            $this->warn('DigiFlazz API exception: ' . $e->getMessage());
            if (File::exists($cacheFile)) {
                $this->info('Fallback ke cache data lokal.');
                return json_decode(File::get($cacheFile), true);
            }
        }

        return null;
    }
}
