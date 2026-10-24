<?php

use App\Models\Transaction;
use App\Services\Notification\NotificationService;
use App\Services\Supplier\DigiflazzService;
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
    $lock = \Illuminate\Support\Facades\Cache::lock('command:orders:reconcile', 120);
    if (! $lock->get()) {
        $this->warn('Proses rekonsiliasi sedang berjalan di proses lain.');
        return;
    }

    try {
        $transactions = Transaction::where('status', 'processing')
            ->where('needs_manual_review', false)
            ->where('created_at', '<=', now()->subMinutes(3))
            ->whereHas('productItem.product', function ($q) {
                $q->where(function ($sub) {
                    $sub->whereNull('supplier_type')
                        ->orWhere('supplier_type', '!=', 'manual');
                });
            })
            ->with(['productItem.product'])
            ->get();

        $this->info("Reconciling {$transactions->count()} processing transactions...");

        foreach ($transactions as $trx) {
            $fulfillment = app(\App\Services\FulfillmentService::class);

            // Guard anti-retry tanpa batas: order yang sudah gagal final atau
            // kehabisan percobaan ditahan untuk admin, bukan dihubungi supplier lagi.
            if ($fulfillment->attemptsExhausted($trx)) {
                $fulfillment->holdForManualReview($trx, sprintf(
                    'Percobaan otomatis supplier habis (%d kali); perlu tindakan admin.',
                    (int) $trx->supplier_attempts
                ));
                $this->warn("Invoice {$trx->invoice_id} ditahan untuk tindakan admin (batas percobaan).");

                continue;
            }

            // Hormati jeda antar percobaan supaya supplier tidak dibanjiri request.
            if (! $fulfillment->canAttemptNow($trx)) {
                continue;
            }

            try {
                $type = strtolower((string) ($trx->productItem?->product?->supplier_type ?? ''));
                $supplier = in_array($type, ['tokovoucher', 'apigames'], true)
                    ? app(\App\Services\Supplier\TokovoucherService::class)
                    : app(DigiflazzService::class);

                $fulfillment->recordAttempt($trx, $supplier->supplierName());

                $res = $supplier->topup($trx, $trx->customer_data ?? []);

                $shouldNotify = false;
                \Illuminate\Support\Facades\DB::transaction(function () use ($trx, $res, &$shouldNotify) {
                    $locked = Transaction::whereKey($trx->id)->lockForUpdate()->first();
                    if (! $locked || $locked->status !== 'processing') {
                        return;
                    }

                    if ($res['status'] === 'completed') {
                        $locked->forceFill([
                            'status' => 'completed',
                            'supplier_ref' => $res['supplier_ref'] ?? $locked->supplier_ref,
                            'supplier_sn' => $res['supplier_sn'] ?? $locked->supplier_sn,
                            'supplier_response' => json_encode($res['raw'] ?? $res),
                            'completed_at' => now(),
                        ])->save();
                        $shouldNotify = true;
                    } elseif ($res['status'] === 'failed') {
                        // Supplier menyatakan gagal: final. Tandai butuh tindakan
                        // admin supaya scheduler berhenti mengirim ulang.
                        $locked->forceFill([
                            'status' => 'failed',
                            'supplier_response' => json_encode($res['raw'] ?? $res),
                            'needs_manual_review' => true,
                            'manual_review_reason' => 'Supplier menyatakan transaksi gagal: '.trim((string) ($res['message'] ?? 'tanpa keterangan')),
                        ])->save();
                    } else {
                        $locked->forceFill([
                            'supplier_response' => json_encode($res['raw'] ?? $res),
                        ])->save();
                    }
                });

                if ($shouldNotify) {
                    app(NotificationService::class)->orderCompleted($trx->fresh());
                    $this->info("Invoice {$trx->invoice_id} completed (SN: {$trx->supplier_sn})");
                } elseif ($res['status'] === 'failed') {
                    $this->warn("Invoice {$trx->invoice_id} failed — ditahan untuk tindakan admin");

                    try {
                        app(\App\Services\Notification\TelegramAlertService::class)->alertFulfillmentFailed(
                            $trx->fresh(),
                            (string) ($trx->fresh()->manual_review_reason ?? 'Supplier menyatakan transaksi gagal')
                        );
                    } catch (\Throwable) {}
                }
            } catch (\App\Services\Supplier\SupplierException $e) {
                // Gagal final (mis. rc 02 "Transaksi Gagal") atau kuota percobaan
                // habis → tahan order; selain itu biarkan di-retry dengan backoff.
                if (! $e->isRetryable() || $fulfillment->attemptsExhausted($trx)) {
                    $fulfillment->holdForManualReview($trx, $e->getMessage());
                    $this->warn("Invoice {$trx->invoice_id} ditahan untuk tindakan admin: {$e->getMessage()}");
                } else {
                    $this->error("Supplier sementara gagal untuk {$trx->invoice_id}: {$e->getMessage()}");
                }
            } catch (\Throwable $e) {
                $this->error("Error reconciling {$trx->invoice_id}: {$e->getMessage()}");
            }
        }
    } finally {
        $lock->release();
    }
})->purpose('Cek status transaksi processing yang tertunda ke supplier');

/*
| Alias lama `supplier:sync-prices` (dipakai manual / cron lama) kini hanya
| meneruskan ke command utama. Implementasi duplikat sebelumnya menulis katalog
| tanpa pengaman kesegaran data dan tanpa membatasi item milik supplier lain,
| jadi jalur itu dihapus supaya hanya ada satu sumber kebenaran.
*/
Artisan::command('supplier:sync-prices', function () {
    $this->warn('supplier:sync-prices sudah digabung ke supplier:sync-digiflazz (fail-closed).');

    return $this->call('supplier:sync-digiflazz');
})->purpose('Alias lama: diteruskan ke supplier:sync-digiflazz');


Schedule::command('orders:expire')->everyMinute();
Schedule::command('orders:reconcile')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('payments:reconcile')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('supplier:sync-digiflazz')->everyTwoHours()->withoutOverlapping();

Artisan::command('payments:reconcile', function () {
    // Jaring pengaman pembayaran: webhook Belibayar bisa hilang, ditolak, atau
    // datang terlambat. Status provider adalah sumber kebenaran, jadi transaksi
    // yang masih pending/expired ditanyakan langsung ke gateway.
    $lock = \Illuminate\Support\Facades\Cache::lock('command:payments:reconcile', 300);
    if (! $lock->get()) {
        $this->warn('Rekonsiliasi pembayaran sedang berjalan di proses lain.');

        return;
    }

    try {
        $client = app(\App\Services\Payment\BelibayarClient::class);
        $transactions = Transaction::where('payment_gateway', 'belibayar')
            ->whereIn('status', ['pending', 'expired'])
            ->whereNotNull('payment_ref')
            ->where('created_at', '<=', now()->subMinutes(2))
            // Konfirmasi terlambat masih diterima hingga 7 hari setelah kedaluwarsa.
            ->where(function ($q) {
                $q->whereNull('expired_at')->orWhere('expired_at', '>=', now()->subDays(7));
            })
            ->orderBy('id')
            ->limit(50)
            ->get();

        $this->info("Memeriksa {$transactions->count()} transaksi Belibayar yang belum final...");
        $recovered = 0;

        foreach ($transactions as $trx) {
            try {
                $data = $client->status((string) $trx->payment_ref);
            } catch (\Throwable $e) {
                $this->error("Status {$trx->invoice_id} gagal: {$e->getMessage()}");

                continue;
            }

            $status = $data['status'] ?? null;

            if ($status === 'paid') {
                $amount = $data['amount'] ?? null;
                $paymentId = (string) ($data['transaction_id'] ?? '');
                $channel = $data['payment_channel'] ?? null;
                $matches = is_numeric($amount)
                    && (int) $amount === (int) $trx->total
                    && $paymentId === (string) $trx->payment_ref
                    && ($channel === null || strcasecmp((string) $channel, (string) $trx->payment_channel) === 0);

                if (! $matches) {
                    \Illuminate\Support\Facades\Log::warning('Rekonsiliasi Belibayar: provider paid tetapi data tidak cocok; tidak diproses.', [
                        'invoice_id' => $trx->invoice_id,
                        'local_total' => (int) $trx->total,
                        'provider_amount' => $amount,
                        'local_ref' => $trx->payment_ref,
                        'provider_ref' => $paymentId,
                        'local_channel' => $trx->payment_channel,
                        'provider_channel' => $channel,
                    ]);
                    $this->warn("{$trx->invoice_id}: provider paid tetapi data tidak cocok; dilewati.");

                    continue;
                }

                $toFulfill = \Illuminate\Support\Facades\DB::transaction(function () use ($trx) {
                    $locked = Transaction::whereKey($trx->id)->lockForUpdate()->first();
                    if (! $locked || ! in_array($locked->status, ['pending', 'expired'], true)) {
                        return null;
                    }

                    $locked->forceFill([
                        'status' => 'paid',
                        'paid_at' => $locked->paid_at ?? now(),
                    ])->save();

                    return $locked->id;
                });

                if ($toFulfill !== null) {
                    app(\App\Services\FulfillmentService::class)->fulfill(Transaction::findOrFail($toFulfill));
                    $recovered++;
                    $this->info("{$trx->invoice_id}: dibayar — dipulihkan lewat rekonsiliasi.");
                }

                continue;
            }

            if (in_array($status, ['expired', 'cancelled'], true) && $trx->status === 'pending') {
                $trx->forceFill(['status' => 'expired'])->save();
                $this->line("{$trx->invoice_id}: ditandai expired sesuai provider.");
            }
        }

        $this->info("Selesai. Transaksi dipulihkan: {$recovered}.");
    } finally {
        $lock->release();
    }
})->purpose('Rekonsiliasi status pembayaran Belibayar langsung ke gateway (jaring pengaman webhook)');

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

