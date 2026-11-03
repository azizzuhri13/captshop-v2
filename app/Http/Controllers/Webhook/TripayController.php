<?php

namespace App\Http\Controllers\Webhook;

use App\Http\Controllers\Controller;
use App\Models\Transaction;
use App\Services\FulfillmentService;
use App\Services\Notification\TelegramAlertService;
use App\Services\Payment\TripayService;
use App\Services\Payment\TripayTransactionState;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Callback Tripay (Closed Payment).
 *
 * Kontrak yang dijaga:
 *  - X-Callback-Signature = HMAC SHA256(raw body, private key). Payload hasil
 *    decode tidak pernah dipakai untuk verifikasi karena byte-nya bisa berbeda.
 *  - Hanya event `payment_status` yang diproses.
 *  - merchant_ref harus sama dengan invoice_id, reference dengan payment_ref
 *    tersimpan, total_amount dengan payable_amount tersimpan (total + fee
 *    pelanggan), payment_method_code dengan kode channel Tripay, dan
 *    is_closed_payment harus 1.
 *  - Klaim `paid` idempoten di dalam row lock; fulfillment berjalan di luar
 *    lock dan hanya untuk pemenang klaim, sehingga supplier tidak dipanggil
 *    dua kali pada callback duplikat/terlambat.
 *  - REFUND tidak pernah membalik order yang sudah dibayar.
 */
class TripayController extends Controller
{
    private const CALLBACK_EVENT = 'payment_status';

    /** Status callback resmi Tripay untuk closed payment. */
    private const KNOWN_STATUSES = ['PAID', 'FAILED', 'EXPIRED', 'REFUND'];

    public function __construct(
        private TripayService $gateway,
        private FulfillmentService $fulfillment,
        private TripayTransactionState $state,
    ) {}

    public function handle(Request $request): JsonResponse
    {
        $raw = $request->getContent();
        $signature = (string) $request->header('X-Callback-Signature', '');

        if (! $this->gateway->verifyWebhook($raw, $signature)) {
            $this->logRejection('signature_invalid', $raw);

            return response()->json(['success' => false, 'message' => 'Invalid signature'], 403);
        }

        $event = (string) $request->header('X-Callback-Event', '');

        if ($event !== self::CALLBACK_EVENT) {
            // Payload sah secara tanda tangan tetapi bukan notifikasi status
            // pembayaran. Jangan sentuh pesanan apa pun.
            Log::info('Webhook Tripay: event callback tidak dikenali; tidak ada tindakan.', [
                'event' => mb_substr($event, 0, 64),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Unrecognized callback event, no action was taken',
            ]);
        }

        $payload = json_decode($raw, true);

        if (! is_array($payload)) {
            $this->logRejection('payload_not_json', $raw);

            return response()->json(['success' => false, 'message' => 'Invalid data sent by Tripay'], 400);
        }

        $merchantRef = $payload['merchant_ref'] ?? null;
        $reference = $payload['reference'] ?? null;
        $status = is_string($payload['status'] ?? null) ? strtoupper(trim($payload['status'])) : null;
        $totalAmount = $this->toInt($payload['total_amount'] ?? null);
        $methodCode = $payload['payment_method_code'] ?? null;

        $shapeReason = $this->shapeRejectionReason($payload, $merchantRef, $reference, $status, $totalAmount, $methodCode);

        if ($shapeReason !== null) {
            $this->logRejection($shapeReason, $raw);

            return response()->json(['success' => false, 'message' => 'Invalid callback payload'], 422);
        }

        /** @var string $merchantRef */
        /** @var string $reference */
        /** @var string $status */
        $outcome = DB::transaction(function () use ($payload, $merchantRef, $reference, $status, $totalAmount, $methodCode) {
            $transaction = Transaction::query()
                ->where('invoice_id', $merchantRef)
                ->lockForUpdate()
                ->first();

            if (! $transaction) {
                return 'missing';
            }

            $mismatch = $this->state->mismatchReason(
                $transaction,
                $reference,
                (int) $totalAmount,
                (string) $methodCode,
            );

            if ($mismatch !== null) {
                return 'mismatch:'.$mismatch;
            }

            return match ($status) {
                'PAID' => $this->state->claimPaid($transaction),
                'EXPIRED' => $this->state->closeUnpaid($transaction, 'expired'),
                'FAILED' => $this->state->closeUnpaid($transaction, 'failed'),
                'REFUND' => $this->state->recordRefund($transaction),
                default => null,
            };
        });

        if ($outcome === 'missing') {
            $this->logRejection('merchant_ref_not_found', $raw);

            return response()->json(['success' => false, 'message' => 'Invoice not found'], 404);
        }

        if (is_string($outcome) && str_starts_with($outcome, 'mismatch')) {
            $this->logRejection($outcome, $raw);

            return response()->json(['success' => false, 'message' => 'Callback does not match the stored transaction'], 422);
        }

        if (is_int($outcome)) {
            $this->fulfillment->fulfill(Transaction::findOrFail($outcome));
        }

        return response()->json(['success' => true]);
    }

    /**
     * Validasi bentuk payload sebelum menyentuh database.
     */
    protected function shapeRejectionReason(
        array $payload,
        mixed $merchantRef,
        mixed $reference,
        mixed $status,
        mixed $totalAmount,
        mixed $methodCode,
    ): ?string {
        if (! is_string($merchantRef) || trim($merchantRef) === '') {
            return 'missing_merchant_ref';
        }

        if (! is_string($reference) || trim($reference) === '') {
            return 'missing_reference';
        }

        if (! is_string($status) || ! in_array($status, self::KNOWN_STATUSES, true)) {
            return 'invalid_status';
        }

        if (! is_int($totalAmount) || $totalAmount <= 0) {
            return 'total_amount_invalid';
        }

        if (! is_string($methodCode) || trim($methodCode) === '') {
            return 'missing_payment_method_code';
        }

        $closed = $payload['is_closed_payment'] ?? null;

        if (! in_array($closed, [1, '1', true], true)) {
            return 'not_closed_payment';
        }

        return null;
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

    /**
     * Catat penolakan tanpa menulis signature, private key, atau data pribadi
     * pelanggan ke log.
     */
    protected function logRejection(string $reason, string $raw): void
    {
        $decoded = json_decode($raw, true);

        $summary = is_array($decoded) ? [
            'keys' => array_keys($decoded),
            'merchant_ref' => $decoded['merchant_ref'] ?? null,
            'reference' => $decoded['reference'] ?? null,
            'status' => $decoded['status'] ?? null,
            'total_amount' => $decoded['total_amount'] ?? null,
            'total_amount_type' => get_debug_type($decoded['total_amount'] ?? null),
            'payment_method_code' => $decoded['payment_method_code'] ?? null,
            'is_closed_payment' => $decoded['is_closed_payment'] ?? null,
        ] : ['bytes' => strlen($raw)];

        Log::warning('Webhook Tripay ditolak', [
            'reason' => $reason,
            'payload' => $summary,
        ]);

        // Callback PAID yang ditolak berarti uang pelanggan sudah masuk tetapi
        // pesanan belum diproses; ini kerugian nyata, jadi eskalasi ke admin.
        if (strtoupper(trim((string) ($decoded['status'] ?? ''))) === 'PAID') {
            try {
                app(TelegramAlertService::class)->send(
                    "🚨 <b>[WEBHOOK TRIPAY DITOLAK]</b>\n"
                    .'Alasan: <code>'.e($reason)."</code>\n"
                    .'Invoice: <code>'.e((string) ($decoded['merchant_ref'] ?? '-'))."</code>\n"
                    .'Referensi: <code>'.e((string) ($decoded['reference'] ?? '-'))."</code>\n"
                    .'Nominal: Rp '.number_format((int) ($decoded['total_amount'] ?? 0), 0, ',', '.')."\n"
                    .'Pembayaran sudah masuk di provider. Periksa manual sebelum memproses pesanan.'
                );
            } catch (\Throwable) {
                Log::error('Gagal mengirim alert penolakan webhook Tripay.');
            }
        }
    }
}