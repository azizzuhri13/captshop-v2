<?php

namespace App\Services;

use App\Models\Transaction;
use App\Services\Notification\NotificationService;
use App\Services\Supplier\SupplierException;
use App\Services\Supplier\SupplierInterface;
use App\Services\Supplier\TokovoucherService;
use Illuminate\Support\Facades\Log;

class FulfillmentService
{
    public function __construct(
        protected SupplierInterface $supplier,
        protected NotificationService $notifications,
    ) {}

    /**
     * Proses fulfillment pesanan yang sudah dibayar: kirim notifikasi paid,
     * panggil supplier top-up, simpan response, lalu kirim notifikasi
     * ke pelanggan.
     */
    public function fulfill(Transaction $transaction): Transaction
    {
        if (! in_array($transaction->status, ['paid', 'processing'], true)) {
            return $transaction;
        }

        if ($transaction->processed_at === null) {
            $this->notify(fn () => $this->notifications->paymentReceived($transaction), $transaction, 'paid');
        }

        $transaction->forceFill(['status' => 'processing', 'processed_at' => now()])->save();

        // Cek jika produk adalah stok akun Netflix / Disney+ / Canva / manual internal
        $productSlug = $transaction->productItem?->product?->slug;
        $supplierType = strtolower((string) ($transaction->productItem?->product?->supplier_type ?? ''));
        if ($productSlug === 'netflix-premium') {
            return $this->fulfillNetflixStock($transaction);
        }

        if ($productSlug === 'disney-plus') {
            return $this->fulfillDisneyStock($transaction);
        }

        if ($productSlug === 'canva-pro') {
            return $this->fulfillCanvaStock($transaction);
        }

        if ($supplierType === 'manual') {
            // Produk manual (CapCut, Viu, Wukong, dsb) tidak boleh dialokasikan stok Netflix.
            // Status tetap processing untuk penanganan manual admin.
            $transaction->forceFill([
                'status' => 'processing',
                'supplier_ref' => 'MANUAL-'.$transaction->invoice_id,
                'supplier_response' => json_encode(['note' => 'Menunggu proses manual admin']),
            ])->save();

            try {
                $productName = $transaction->productItem?->product?->name ?? 'Produk';
                $itemName = $transaction->productItem?->name ?? '';
                app(\App\Services\Notification\TelegramAlertService::class)->send(
                    "📌 <b>[PESANAN MANUAL MEMERLUKAN PROSES]</b>\n"
                    ."Platform: <b>".(\App\Models\SiteSetting::get('site_name') ?? 'CAPTSHOP')."</b>\n"
                    ."Invoice: <code>{$transaction->invoice_id}</code>\n"
                    ."Produk: <b>{$productName} — {$itemName}</b>\n"
                    ."Customer: {$transaction->customer_phone}\n"
                    ."Waktu: ".now()->format('d/m/Y H:i:s')." WIB"
                );
            } catch (\Throwable) {}

            return $transaction;
        }

        try {
            $supplier = $this->resolveSupplier($transaction);
            $result = $supplier->topup($transaction, $transaction->customer_data ?? []);

            $status = $result['status'] === 'completed' ? 'completed' : 'processing';

            $transaction->forceFill([
                'status' => $status,
                'supplier_ref' => $result['supplier_ref'] ?? null,
                'supplier_sn' => $result['supplier_sn'] ?? null,
                'supplier_response' => json_encode($result['raw'] ?? $result),
                'completed_at' => $status === 'completed' ? now() : null,
            ])->save();

            if ($status === 'completed') {
                $this->notify(fn () => $this->notifications->orderCompleted($transaction), $transaction, 'completed');
            }
        } catch (SupplierException $e) {
            Log::error('Fulfillment gagal untuk '.$transaction->invoice_id.': '.$e->getMessage());

            $transaction->forceFill([
                'status' => 'processing',
                'supplier_response' => json_encode(['error' => $e->getMessage()]),
            ])->save();

            try {
                app(\App\Services\Notification\TelegramAlertService::class)->alertFulfillmentFailed($transaction, $e->getMessage());
            } catch (\Throwable) {}
        }

        return $transaction;
    }

    /**
     * Alokasi stok akun Netflix internal secara atomic dan aman dari race-condition.
     */
    protected function fulfillNetflixStock(Transaction $transaction): Transaction
    {
        return \Illuminate\Support\Facades\DB::transaction(function () use ($transaction) {
            $account = \App\Models\NetflixAccount::where('status', 'ready')
                ->lockForUpdate()
                ->first();

            if (! $account) {
                Log::warning('Stok akun Netflix habis untuk transaksi '.$transaction->invoice_id);
                $transaction->forceFill([
                    'status' => 'processing',
                    'supplier_response' => json_encode(['error' => 'Stok akun Netflix habis, menunggu restock']),
                ])->save();

                try {
                    app(\App\Services\Notification\TelegramAlertService::class)->alertFulfillmentFailed(
                        $transaction,
                        'Stok akun Netflix habis di database! Harap segera restock.'
                    );
                } catch (\Throwable) {}

                return $transaction;
            }

            $account->forceFill([
                'status' => 'sold',
                'transaction_id' => $transaction->id,
                'sold_at' => now(),
            ])->save();

            $snPayload = [
                'email' => $account->email,
                'password' => $account->password,
                'profile' => $account->profile_name,
                'pin' => $account->pin,
            ];

            $snText = sprintf(
                'Email: %s | Pass: %s%s%s',
                $account->email,
                $account->password,
                $account->profile_name ? ' | '.$account->profile_name : '',
                $account->pin ? ' | PIN: '.$account->pin : ''
            );

            $transaction->forceFill([
                'status' => 'completed',
                'supplier_ref' => 'STOCK-NTFX-'.$account->id,
                'supplier_sn' => $snText,
                'supplier_response' => json_encode($snPayload),
                'completed_at' => now(),
            ])->save();

            $this->notify(fn () => $this->notifications->orderCompleted($transaction), $transaction, 'completed');

            return $transaction;
        });
    }

    /**
     * Alokasi stok akun Disney+ internal secara atomic dan aman dari race-condition.
     */
    protected function fulfillDisneyStock(Transaction $transaction): Transaction
    {
        return \Illuminate\Support\Facades\DB::transaction(function () use ($transaction) {
            $account = \App\Models\DisneyAccount::where('status', 'ready')
                ->lockForUpdate()
                ->first();

            if (! $account) {
                Log::warning('Stok akun Disney+ habis untuk transaksi '.$transaction->invoice_id);
                $transaction->forceFill([
                    'status' => 'processing',
                    'supplier_response' => json_encode(['error' => 'Stok akun Disney+ habis, menunggu restock']),
                ])->save();

                try {
                    app(\App\Services\Notification\TelegramAlertService::class)->alertFulfillmentFailed(
                        $transaction,
                        'Stok akun Disney+ habis di database! Harap segera restock.'
                    );
                } catch (\Throwable) {}

                return $transaction;
            }

            $account->forceFill([
                'status' => 'sold',
                'transaction_id' => $transaction->id,
                'sold_at' => now(),
            ])->save();

            $snPayload = [
                'email' => $account->email,
                'password' => $account->password,
                'profile' => $account->profile_name,
                'pin' => $account->pin,
            ];

            $snText = sprintf(
                'Email: %s | Pass: %s%s%s',
                $account->email,
                $account->password,
                $account->profile_name ? ' | '.$account->profile_name : '',
                $account->pin ? ' | PIN: '.$account->pin : ''
            );

            $transaction->forceFill([
                'status' => 'completed',
                'supplier_ref' => 'STOCK-DSNY-'.$account->id,
                'supplier_sn' => $snText,
                'supplier_response' => json_encode($snPayload),
                'completed_at' => now(),
            ])->save();

            $this->notify(fn () => $this->notifications->orderCompleted($transaction), $transaction, 'completed');

            return $transaction;
        });
    }

    /**
     * Alokasi stok akun Canva Pro internal secara atomic dan aman dari race-condition.
     */
    protected function fulfillCanvaStock(Transaction $transaction): Transaction
    {
        return \Illuminate\Support\Facades\DB::transaction(function () use ($transaction) {
            $account = \App\Models\CanvaAccount::where('status', 'ready')
                ->lockForUpdate()
                ->first();

            if (! $account) {
                Log::warning('Stok akun Canva Pro habis untuk transaksi '.$transaction->invoice_id);
                $transaction->forceFill([
                    'status' => 'processing',
                    'supplier_response' => json_encode(['error' => 'Stok akun Canva Pro habis, menunggu restock']),
                ])->save();

                try {
                    app(\App\Services\Notification\TelegramAlertService::class)->alertFulfillmentFailed(
                        $transaction,
                        'Stok akun Canva Pro habis di database! Harap segera restock.'
                    );
                } catch (\Throwable) {}

                return $transaction;
            }

            $account->forceFill([
                'status' => 'sold',
                'transaction_id' => $transaction->id,
                'sold_at' => now(),
            ])->save();

            $snPayload = [
                'email' => $account->email,
                'password' => $account->password,
            ];

            $snText = sprintf(
                'Email: %s | Pass: %s',
                $account->email,
                $account->password
            );

            $transaction->forceFill([
                'status' => 'completed',
                'supplier_ref' => 'STOCK-CNVA-'.$account->id,
                'supplier_sn' => $snText,
                'supplier_response' => json_encode($snPayload),
                'completed_at' => now(),
            ])->save();

            $this->notify(fn () => $this->notifications->orderCompleted($transaction), $transaction, 'completed');

            return $transaction;
        });
    }

    /**
     * Tentukan supplier yang digunakan untuk transaksi ini.
     * Mengutamakan supplier_type pada produk jika didefinisikan.
     */
    protected function resolveSupplier(Transaction $transaction): SupplierInterface
    {
        $type = strtolower((string) ($transaction->productItem?->product?->supplier_type ?? ''));

        if (in_array($type, ['tokovoucher', 'apigames'], true)) {
            return $this->supplier instanceof TokovoucherService
                ? $this->supplier
                : app(TokovoucherService::class);
        }

        return $this->supplier;
    }

    /**
     * Kirim notifikasi; kegagalan channel notifikasi tidak boleh
     * menggagalkan proses fulfillment.
     */
    protected function notify(callable $notifier, Transaction $transaction, string $event): void
    {
        try {
            $notifier();
        } catch (\Throwable $e) {
            Log::error('Gagal mengirim notifikasi '.$event.' untuk '.$transaction->invoice_id.': '.$e->getMessage());
        }
    }
}
