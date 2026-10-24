<?php

namespace App\Services;

use App\Models\Transaction;
use App\Services\Notification\NotificationService;
use App\Services\Notification\TelegramAlertService;
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

    /*
    |--------------------------------------------------------------------------
    | Guard fulfillment supplier
    |--------------------------------------------------------------------------
    |
    | Fulfillment otomatis tidak boleh mengirim ulang transaksi tanpa batas.
    | Dua penahan: (1) supplier menyatakan gagal final (tidak akan berubah
    | walau diulang) dan (2) jumlah percobaan otomatis sudah mencapai batas.
    | Order yang ditahan diberi flag `needs_manual_review` supaya scheduler
    | berhenti dan admin menindaklanjuti (refund / fulfillment manual).
    |
    */

    /** Batas percobaan otomatis sebelum order ditahan untuk admin. */
    public function maxAttempts(): int
    {
        return max(1, (int) config('supplier.max_attempts', 3));
    }

    /** Jeda minimum (menit) sebelum percobaan otomatis berikutnya. */
    public function backoffMinutesFor(int $attempt): int
    {
        $schedule = array_values(array_filter((array) config('supplier.retry_backoff_minutes', [5, 15, 60]), 'is_numeric'));

        if ($schedule === []) {
            return 0;
        }

        $index = min(max($attempt, 1), count($schedule)) - 1;

        return (int) $schedule[$index];
    }

    /** Order ditahan untuk tindakan admin? */
    public function needsManualReview(Transaction $transaction): bool
    {
        return (bool) $transaction->needs_manual_review;
    }

    /** Jumlah percobaan otomatis sudah mencapai batas? */
    public function attemptsExhausted(Transaction $transaction): bool
    {
        return (int) $transaction->supplier_attempts >= $this->maxAttempts();
    }

    /**
     * Boleh dikirim ke supplier sekarang? Menghormati penahanan admin dan
     * jeda (backoff) antar percobaan.
     */
    public function canAttemptNow(Transaction $transaction): bool
    {
        if ($this->needsManualReview($transaction) || $this->attemptsExhausted($transaction)) {
            return false;
        }

        $last = $transaction->supplier_last_attempt_at;

        if ($last === null) {
            return true;
        }

        return $last->addMinutes($this->backoffMinutesFor((int) $transaction->supplier_attempts))->isPast();
    }

    /**
     * Catat percobaan kirim ke supplier SEBELUM request dikirim, supaya
     * kegagalan jaringan/crash pun tetap terhitung.
     */
    public function recordAttempt(Transaction $transaction, string $supplierName): void
    {
        $transaction->forceFill([
            'supplier_name' => $supplierName,
            'supplier_attempts' => (int) $transaction->supplier_attempts + 1,
            'supplier_last_attempt_at' => now(),
        ])->save();
    }

    /**
     * Tahan order untuk tindakan admin: status final + flag manual review,
     * sehingga tidak dikirim ulang ke supplier oleh scheduler mana pun.
     */
    public function holdForManualReview(Transaction $transaction, string $reason, string $status = 'failed'): Transaction
    {
        $transaction->forceFill([
            'status' => $status,
            'needs_manual_review' => true,
            'manual_review_reason' => $reason,
            'supplier_response' => json_encode(['error' => $reason]),
        ])->save();

        try {
            app(TelegramAlertService::class)->alertFulfillmentFailed($transaction, $reason);
        } catch (\Throwable) {}

        return $transaction;
    }

    /**
     * Coba ulang secara manual (dipicu admin): buka penahan guard, lalu
     * jalankan fulfillment sekali lagi.
     *
     * Tindakan manusia yang eksplisit boleh mengulang order yang sudah ditahan
     * (mis. setelah SKU diperbaiki atau restock), sedangkan percobaan otomatis
     * tetap dibatasi supaya tidak kembali jadi retry tanpa batas.
     */
    public function retryManually(Transaction $transaction): Transaction
    {
        $transaction->forceFill([
            'status' => $transaction->status === 'failed' ? 'processing' : $transaction->status,
            'needs_manual_review' => false,
            'manual_review_reason' => null,
            'supplier_attempts' => 0,
        ])->save();

        return $this->fulfill($transaction);
    }

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

        // Order yang sudah ditahan untuk tindakan admin tidak boleh dikirim
        // ulang ke supplier: mengulang transaksi yang gagal final tidak
        // mengubah hasil dan berisiko top-up ganda ke pelanggan.
        if ($this->needsManualReview($transaction)) {
            Log::info('Fulfillment dilewati: order ditahan untuk tindakan admin.', [
                'invoice_id' => $transaction->invoice_id,
                'reason' => $transaction->manual_review_reason,
            ]);

            return $transaction;
        }

        if ($this->attemptsExhausted($transaction)) {
            return $this->holdForManualReview($transaction, sprintf(
                'Percobaan otomatis supplier sudah mencapai batas (%d kali).',
                $this->maxAttempts()
            ));
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

        if ($productSlug === 'capcut') {
            return $this->fulfillCapcutStock($transaction);
        }

        if ($supplierType === 'manual') {
            // Produk manual (Viu, Wukong, dsb) tidak boleh dialokasikan stok Netflix/Canva/Capcut.
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
            $this->recordAttempt($transaction, $supplier->supplierName());

            $result = $supplier->topup($transaction, $transaction->customer_data ?? []);

            if ($result['status'] === 'failed') {
                // Supplier menyatakan gagal: hasilnya final, mengulang
                // transaksi yang sama tidak akan berubah.
                return $this->holdForManualReview(
                    $transaction,
                    'Supplier menyatakan transaksi gagal: '.trim((string) ($result['message'] ?? 'tanpa keterangan'))
                );
            }

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

            // Gagal final dari supplier (mis. rc 02 "Transaksi Gagal") atau
            // percobaan otomatis sudah habis: tahan order, jangan di-retry lagi.
            if (! $e->isRetryable() || $this->attemptsExhausted($transaction)) {
                return $this->holdForManualReview($transaction, $e->getMessage());
            }

            $transaction->forceFill([
                'status' => 'processing',
                'supplier_response' => json_encode(['error' => $e->getMessage()]),
            ])->save();

            try {
                app(TelegramAlertService::class)->alertFulfillmentFailed($transaction, $e->getMessage());
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
                'channel' => 'web',
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
                'channel' => 'web',
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
                'channel' => 'web',
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
     * Alokasi stok akun CapCut internal secara atomic dan aman dari race-condition.
     */
    protected function fulfillCapcutStock(Transaction $transaction): Transaction
    {
        return \Illuminate\Support\Facades\DB::transaction(function () use ($transaction) {
            $account = \App\Models\CapcutAccount::where('status', 'ready')
                ->lockForUpdate()
                ->first();

            if (! $account) {
                Log::warning('Stok akun CapCut habis untuk transaksi '.$transaction->invoice_id);
                $transaction->forceFill([
                    'status' => 'processing',
                    'supplier_response' => json_encode(['error' => 'Stok akun CapCut habis, menunggu restock']),
                ])->save();

                try {
                    app(\App\Services\Notification\TelegramAlertService::class)->alertFulfillmentFailed(
                        $transaction,
                        'Stok akun CapCut habis di database! Harap segera restock.'
                    );
                } catch (\Throwable) {}

                return $transaction;
            }

            $account->forceFill([
                'status' => 'sold',
                'channel' => 'web',
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
                'supplier_ref' => 'STOCK-CPCT-'.$account->id,
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
