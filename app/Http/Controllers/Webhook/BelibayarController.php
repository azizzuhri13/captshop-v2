<?php

namespace App\Http\Controllers\Webhook;

use App\Http\Controllers\Controller;
use App\Models\Transaction;
use App\Services\FulfillmentService;
use App\Services\Payment\BelibayarService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class BelibayarController extends Controller
{
    public function __construct(private BelibayarService $gateway, private FulfillmentService $fulfillment) {}

    public function handle(Request $request): JsonResponse
    {
        $raw = $request->getContent();
        if (! $this->gateway->verifyWebhook($raw, (string) $request->header('X-Belibayar-Signature', ''))) {
            $this->logRejection('signature_invalid', $raw);

            return response()->json(['success' => false], 403);
        }
        $payload = json_decode($raw, true);
        if (! is_array($payload)) {
            $this->logRejection('payload_not_json', $raw);

            return response()->json(['success' => false], 400);
        }
        // This application does not request withdrawals. Acknowledge unrelated signed events.
        if (array_key_exists('event', $payload)) {
            return response()->json(['success' => true]);
        }
        $reference = $payload['reference'] ?? null;
        $paymentId = $payload['transaction_id'] ?? null;
        $status = $payload['status'] ?? null;
        $merchantId = \App\Support\Credentials::get('belibayar_merchant_id', config('payment.belibayar.merchant_id'));
        $shapeReason = $this->shapeRejectionReason($payload, $reference, $paymentId, $status);
        if ($shapeReason !== null) {
            $this->logRejection($shapeReason, $raw);

            return response()->json(['success' => false], 422);
        }
        // merchant_id bukan bukti keaslian: signature atas raw body sudah mengikat pengirim.
        if (isset($payload['merchant_id']) && $payload['merchant_id'] !== $merchantId) {
            Log::warning('Webhook Belibayar merchant_id tidak cocok dengan konfigurasi; diabaikan.', [
                'payload_merchant_id' => $this->maskCode((string) $payload['merchant_id']),
                'configured_merchant_id' => $this->maskCode((string) $merchantId),
            ]);
        }
        $toFulfill = DB::transaction(function () use ($payload, $reference, $paymentId, $status) {
            $transaction = Transaction::query()->where('invoice_id', $reference)->lockForUpdate()->first();
            if (! $transaction) {
                return 'missing';
            }
            $mismatch = $this->transactionMismatchReason($transaction, $payload, $paymentId);
            if ($mismatch !== null) {
                return 'mismatch:'.$mismatch;
            }
            if ($status === 'paid') {
                if (in_array($transaction->status, ['pending', 'expired'], true)) {
                    $transaction->forceFill(['status' => 'paid', 'paid_at' => now()])->save();
                    return $transaction->id;
                }
            } elseif ($transaction->status === 'pending') {
                $transaction->forceFill(['status' => 'expired'])->save();
            }
            return null;
        });
        if ($toFulfill === 'missing') {
            $this->logRejection('reference_not_found', $raw);

            return response()->json(['success' => false], 404);
        }
        if (is_string($toFulfill) && str_starts_with($toFulfill, 'mismatch')) {
            $this->logRejection($toFulfill, $raw);

            return response()->json(['success' => false], 422);
        }
        if (is_int($toFulfill)) {
            $this->fulfillment->fulfill(Transaction::findOrFail($toFulfill));
        }
        return response()->json(['success' => true]);
    }

    /**
     * Validasi bentuk payload sebelum menyentuh database.
     * Mengembalikan alasan penolakan, atau null bila bentuknya sah.
     */
    protected function shapeRejectionReason(array $payload, mixed $reference, mixed $paymentId, mixed $status): ?string
    {
        if (! is_string($reference) || $reference === '') {
            return 'missing_reference';
        }
        if (! is_string($paymentId) || $paymentId === '') {
            return 'missing_transaction_id';
        }
        if (! in_array($status, ['paid', 'expired', 'cancelled'], true)) {
            return 'invalid_status';
        }
        $amount = $payload['amount'] ?? null;
        if (! is_int($amount) && ! (is_string($amount) && ctype_digit($amount))) {
            return 'amount_invalid';
        }

        return null;
    }

    /**
     * Cocokkan payload dengan transaksi yang tersimpan.
     * Mengembalikan nama field yang tidak cocok, atau null bila cocok.
     */
    protected function transactionMismatchReason(Transaction $transaction, array $payload, string $paymentId): ?string
    {
        if ($transaction->payment_gateway !== 'belibayar') {
            return 'gateway';
        }
        if ((int) $transaction->total !== (int) $payload['amount']) {
            return 'amount';
        }
        if ($transaction->payment_ref !== $paymentId) {
            return 'transaction_id';
        }
        if (($payload['payment_channel'] ?? null) !== $transaction->payment_channel) {
            return 'payment_channel';
        }

        return null;
    }

    /**
     * Samarkan kode merchant pada log: cukup untuk mencocokkan dengan dasbor
     * tanpa menuliskan nilai lengkap ke berkas log.
     */
    protected function maskCode(string $value): string
    {
        if ($value === '') {
            return '[kosong]';
        }

        $len = strlen($value);

        return $len <= 5
            ? str_repeat('*', $len)."(len={$len})"
            : substr($value, 0, 3).'***'.substr($value, -2)."(len={$len})";
    }

    /**
     * Catat penolakan webhook beserta ringkasan payload (tanpa PII/kredensial)
     * agar kegagalan berulang bisa didiagnosa tanpa menebak.
     */
    protected function logRejection(string $reason, string $raw): void
    {
        $decoded = json_decode($raw, true);
        $merchantId = \App\Support\Credentials::get('belibayar_merchant_id', config('payment.belibayar.merchant_id'));

        $summary = is_array($decoded) ? [
            'keys' => array_keys($decoded),
            'reference' => $decoded['reference'] ?? null,
            'transaction_id' => $decoded['transaction_id'] ?? null,
            'status' => $decoded['status'] ?? null,
            'amount' => $decoded['amount'] ?? null,
            'amount_type' => get_debug_type($decoded['amount'] ?? null),
            'payment_channel' => $decoded['payment_channel'] ?? null,
            'method_api' => $decoded['method_api'] ?? null,
            'merchant_id_present' => isset($decoded['merchant_id']),
            'merchant_id_matches' => isset($decoded['merchant_id']) && $decoded['merchant_id'] === $merchantId,
        ] : ['bytes' => strlen($raw), 'content_type' => request()->header('Content-Type')];

        Log::warning('Webhook Belibayar ditolak', [
            'reason' => $reason,
            'payload' => $summary,
        ]);

        // Notifikasi "paid" yang ditolak berarti uang pelanggan sudah masuk tetapi
        // pesanan tidak diproses. Ini kerugian nyata, jadi jangan hanya masuk log.
        if (($decoded['status'] ?? null) === 'paid') {
            try {
                app(\App\Services\Notification\TelegramAlertService::class)->send(
                    "🚨 <b>[WEBHOOK BELIBAYAR DITOLAK]</b>\n"
                    .'Alasan: <code>'.e($reason)."</code>\n"
                    .'Invoice: <code>'.e((string) ($decoded['reference'] ?? '-'))."</code>\n"
                    .'Nominal: Rp '.number_format((int) ($decoded['amount'] ?? 0), 0, ',', '.')."\n"
                    .'Pembayaran sudah masuk di provider. Jalankan <code>php artisan payments:reconcile</code> untuk memulihkan pesanan.'
                );
            } catch (\Throwable $e) {
                Log::error('Gagal mengirim alert penolakan webhook Belibayar: '.$e->getMessage());
            }
        }
    }
}
