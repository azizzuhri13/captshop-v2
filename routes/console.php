<?php

use App\Models\ProductItem;
use App\Models\Transaction;
use App\Services\Notification\NotificationService;
use App\Services\Supplier\DigiflazzClient;
use App\Services\Supplier\DigiflazzService;
use App\Support\Credentials;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote')->hourly();

Artisan::command('orders:expire', function () {
    $count = Transaction::where('status', 'pending')
        ->whereNotNull('expired_at')
        ->where('expired_at', '<', now())
        ->update(['status' => 'expired']);
    $this->info("Expired {$count} transactions.");
})->purpose('Batalkan transaksi pending yang melewati batas expired_at');

Artisan::command('orders:reconcile', function () {
    $transactions = Transaction::where('status', 'processing')
        ->where('created_at', '<=', now()->subMinutes(3))
        ->where('created_at', '>=', now()->subHours(24))
        ->get();

    $this->info("Reconciling {$transactions->count()} processing transactions...");

    foreach ($transactions as $trx) {
        try {
            $service = app(DigiflazzService::class);
            $res = $service->topup($trx, $trx->customer_data ?? []);
            if ($res['status'] === 'completed') {
                $trx->forceFill([
                    'status' => 'completed',
                    'supplier_ref' => $res['supplier_ref'] ?? $trx->supplier_ref,
                    'supplier_sn' => $res['supplier_sn'] ?? $trx->supplier_sn,
                    'supplier_response' => json_encode($res['raw'] ?? $res),
                    'completed_at' => now(),
                ])->save();
                app(NotificationService::class)->orderCompleted($trx);
                $this->info("Invoice {$trx->invoice_id} completed (SN: {$trx->supplier_sn})");
            } elseif ($res['status'] === 'failed') {
                $trx->forceFill([
                    'status' => 'failed',
                    'supplier_response' => json_encode($res['raw'] ?? $res),
                ])->save();
                $this->warn("Invoice {$trx->invoice_id} failed");
            }
        } catch (\Throwable $e) {
            $this->error("Error reconciling {$trx->invoice_id}: {$e->getMessage()}");
        }
    }
})->purpose('Cek status transaksi processing yang tertunda ke supplier');

Artisan::command('supplier:sync-prices', function () {
    $client = app(DigiflazzClient::class);
    $user = (string) Credentials::get('digiflazz_username', config('supplier.digiflazz.username'));
    $key = (string) Credentials::get('digiflazz_api_key', config('supplier.digiflazz.api_key'));

    if (empty($user) || empty($key)) {
        $this->error('Kredensial DigiFlazz belum disetel.');
        return;
    }

    try {
        $res = $client->post('/price-list', [
            'cmd' => 'pre-paid',
            'username' => $user,
            'sign' => md5($user . $key . 'pricelist'),
        ]);

        $items = $res['data'] ?? [];
        if (empty($items)) {
            $this->warn('Tidak ada data produk dari DigiFlazz.');
            return;
        }

        $priceMap = [];
        foreach ($items as $item) {
            if (!empty($item['buyer_sku_code'])) {
                $priceMap[$item['buyer_sku_code']] = $item;
            }
        }

        $productItems = ProductItem::whereNotNull('supplier_item_code')->get();
        $updated = 0;

        foreach ($productItems as $pItem) {
            $sku = $pItem->supplier_item_code;
            if (isset($priceMap[$sku])) {
                $upstream = $priceMap[$sku];
                $buyPrice = (float) ($upstream['price'] ?? 0);
                if ($buyPrice > 0) {
                    $sellPrice = ceil(($buyPrice * 1.07) / 100) * 100;
                    $isActive = ($upstream['buyer_product_status'] ?? true) && ($upstream['seller_product_status'] ?? true);
                    $pItem->update([
                        'buy_price' => $buyPrice,
                        'sell_price' => $sellPrice,
                        'is_active' => $isActive,
                    ]);
                    $updated++;
                }
            }
        }

        $this->info("Sync selesai: {$updated} item diperbarui dengan margin 7%.");
    } catch (\Throwable $e) {
        $this->error('Sync DigiFlazz gagal: ' . $e->getMessage());
    }
})->purpose('Sinkronisasi harga beli dan jual dari supplier DigiFlazz');

Schedule::command('orders:expire')->everyMinute();
Schedule::command('orders:reconcile')->everyFiveMinutes();
Schedule::command('supplier:sync-prices')->cron('0 */2 * * *');

Artisan::command('supplier:check-balance', function () {
    try {
        $service = app(DigiflazzService::class);
        $res = $service->checkBalance();
        $balance = (float) ($res['balance'] ?? 0);
        $this->info('Saldo DigiFlazz saat ini: Rp ' . number_format($balance, 0, ',', '.'));

        if ($balance < 100000) {
            $this->warn('Saldo di bawah Rp 100.000! Mengirim alert...');
            app(\App\Services\Notification\TelegramAlertService::class)->alertLowBalance($balance, 'DigiFlazz');
        }
    } catch (\Throwable $e) {
        $this->error('Gagal cek saldo DigiFlazz: ' . $e->getMessage());
    }
})->purpose('Cek saldo DigiFlazz dan kirim alert jika di bawah Rp 100.000');

Schedule::command('supplier:check-balance')->hourly();

