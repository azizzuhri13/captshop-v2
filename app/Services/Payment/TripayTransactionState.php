<?php

namespace App\Services\Payment;

use App\Models\CapcutAccount;
use App\Models\Transaction;
use App\Services\Notification\TelegramAlertService;
use Illuminate\Support\Facades\Log;

/**
 * Aturan pencocokan dan transisi status Tripay.
 *
 * Dipakai bersama oleh webhook (callback) dan command rekonsiliasi supaya
 * keduanya tidak pernah berbeda pendapat soal apa yang dianggap cocok:
 *  - merchant_ref, reference provider, nominal yang harus dibayar, dan kode
 *    metode harus sama persis dengan data tersimpan;
 *  - klaim `paid` idempoten dan hanya satu pemanggil yang menang;
 *  - status paid/processing/completed tidak pernah diturunkan.
 *
 * Instance ini tidak melakukan request jaringan; pemanggil yang menyediakan
 * data provider dan memanggil fulfillment di luar lock.
 */
class TripayTransactionState
{
    /**
     * Nama field yang tidak cocok, atau null bila semuanya cocok.
     */
    public function mismatchReason(
        Transaction $transaction,
        string $reference,
        int $payableAmount,
        string $methodCode,
    ): ?string {
        if ($transaction->payment_gateway !== 'tripay') {
            return 'gateway';
        }

        if ((string) $transaction->payment_ref === '' || ! hash_equals((string) $transaction->payment_ref, $reference)) {
            return 'reference';
        }

        $expectedChannel = $this->expectedChannel($transaction);

        if ($expectedChannel === '' || strtoupper(trim($methodCode)) !== $expectedChannel) {
            return 'payment_method_code';
        }

        $expectedAmount = $this->payableAmount($transaction);

        if ($expectedAmount === null || $payableAmount !== $expectedAmount) {
            return 'total_amount';
        }

        return null;
    }

    /**
     * Klaim status paid secara idempoten. Hanya transaksi yang belum pernah
     * dibayar (pending/expired, atau failed tanpa paid_at) yang boleh diklaim,
     * sehingga callback/command duplikat tidak pernah memicu fulfillment kedua.
     */
    public function claimPaid(Transaction $transaction): ?int
    {
        $claimable = in_array($transaction->status, ['pending', 'expired'], true)
            || ($transaction->status === 'failed' && $transaction->paid_at === null);

        if (! $claimable) {
            return null;
        }

        $transaction->forceFill(['status' => 'paid', 'paid_at' => now()])->save();

        return (int) $transaction->id;
    }

    /**
     * Tutup transaksi yang belum dibayar. Tidak pernah menurunkan status yang
     * sudah paid/processing/completed.
     */
    public function closeUnpaid(Transaction $transaction, string $status): ?int
    {
        if ($transaction->status !== 'pending' || $transaction->paid_at !== null) {
            return null;
        }

        $transaction->forceFill(['status' => $status])->save();
        CapcutAccount::releaseForTransaction((int) $transaction->id);

        return null;
    }

    /**
     * REFUND hanya dicatat dan dieskalasi ke admin. Uang pelanggan sudah
     * diterima dan order mungkin sudah dipenuhi; membalik status di sini akan
     * menyembunyikan pekerjaan supplier yang sudah terjadi.
     */
    public function recordRefund(Transaction $transaction): ?int
    {
        if ($transaction->paid_at === null && ! in_array($transaction->status, ['paid', 'processing', 'completed', 'failed'], true)) {
            // Refund untuk transaksi yang belum pernah dibayar tidak mengubah apa pun.
            return null;
        }

        Log::warning('Tripay REFUND diterima; status pesanan tidak dibalik.', [
            'invoice_id' => $transaction->invoice_id,
            'status' => $transaction->status,
            'paid_at' => $transaction->paid_at?->toIso8601String(),
        ]);

        try {
            app(TelegramAlertService::class)->send(
                "⚠️ <b>[REFUND TRIPAY]</b>\n"
                .'Invoice: <code>'.e((string) $transaction->invoice_id)."</code>\n"
                .'Status pesanan: <code>'.e((string) $transaction->status)."</code>\n"
                .'Status tidak dibalik otomatis. Periksa mutasi dan tindak lanjuti manual.'
            );
        } catch (\Throwable) {
            // Alert adalah upaya terbaik; kegagalannya tidak boleh membuat
            // Tripay mengulang callback refund tanpa akhir.
        }

        return null;
    }

    /** Kode channel Tripay yang tersimpan saat checkout (fallback: mapping config). */
    public function expectedChannel(Transaction $transaction): string
    {
        $stored = $transaction->payment_instructions['channel'] ?? null;

        if (is_string($stored) && trim($stored) !== '') {
            return strtoupper(trim($stored));
        }

        $map = config('payment.tripay.channels', []);
        $internal = strtoupper(trim((string) $transaction->payment_channel));

        return is_array($map) && isset($map[$internal]) && is_string($map[$internal])
            ? strtoupper(trim($map[$internal]))
            : '';
    }

    /** Nominal yang harus dibayar pelanggan: total pesanan + fee pelanggan. */
    public function payableAmount(Transaction $transaction): ?int
    {
        $stored = $transaction->payment_instructions['payable_amount'] ?? null;
        $payable = $this->toInt($stored);

        if ($payable !== null && $payable > 0) {
            return $payable;
        }

        $total = $this->toInt($transaction->total);

        return $total !== null && $total > 0 ? $total : null;
    }

    private function toInt(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_float($value) && floor($value) === $value) {
            return (int) $value;
        }

        if (is_string($value) && preg_match('/^\d+$/D', $value) === 1) {
            return (int) $value;
        }

        return null;
    }
}