<?php

namespace App\Http\Controllers\Webhook;

use App\Http\Controllers\Controller;
use App\Models\Transaction;
use App\Services\FulfillmentService;
use App\Services\Payment\BelibayarService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class BelibayarController extends Controller
{
    public function __construct(private BelibayarService $gateway, private FulfillmentService $fulfillment) {}

    public function handle(Request $request): JsonResponse
    {
        if (! $this->gateway->verifyWebhook($request->getContent(), (string) $request->header('X-Belibayar-Signature', ''))) {
            return response()->json(['success' => false], 403);
        }
        $payload = json_decode($request->getContent(), true);
        if (! is_array($payload)) {
            return response()->json(['success' => false], 400);
        }
        // This application does not request withdrawals. Acknowledge unrelated signed events.
        if (array_key_exists('event', $payload)) {
            return response()->json(['success' => true]);
        }
        $reference = $payload['reference'] ?? null;
        $paymentId = $payload['transaction_id'] ?? null;
        $status = $payload['status'] ?? null;
        if (! is_string($reference) || ! is_string($paymentId) || ! in_array($status, ['paid', 'expired', 'cancelled'], true)
            || ! is_int($payload['amount'] ?? null) || ! is_string($payload['merchant_id'] ?? null)
            || ! \App\Support\Credentials::get('belibayar_merchant_id', config('payment.belibayar.merchant_id'))) {
            return response()->json(['success' => false], 422);
        }
        $toFulfill = DB::transaction(function () use ($payload, $reference, $paymentId, $status) {
            $transaction = Transaction::query()->where('invoice_id', $reference)->lockForUpdate()->first();
            if (! $transaction) {
                return 'missing';
            }
            if ($transaction->payment_gateway !== 'belibayar'
                || (int) $transaction->total !== $payload['amount']
                || $transaction->payment_ref !== $paymentId
                || ($payload['method_api'] ?? null) !== 'direct'
                || ($payload['payment_channel'] ?? null) !== $transaction->payment_channel
                || $payload['merchant_id'] !== \App\Support\Credentials::get('belibayar_merchant_id', config('payment.belibayar.merchant_id'))) {
                return 'mismatch';
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
            return response()->json(['success' => false], 404);
        }
        if ($toFulfill === 'mismatch') {
            return response()->json(['success' => false], 422);
        }
        if (is_int($toFulfill)) {
            $this->fulfillment->fulfill(Transaction::findOrFail($toFulfill));
        }
        return response()->json(['success' => true]);
    }
}
