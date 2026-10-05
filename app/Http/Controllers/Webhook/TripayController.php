<?php

namespace App\Http\Controllers\Webhook;

use App\Http\Controllers\Controller;
use App\Models\Transaction;
use App\Services\FulfillmentService;
use App\Services\Payment\PaymentGatewayInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TripayController extends Controller
{
    public function __construct(
        protected PaymentGatewayInterface $gateway,
        protected FulfillmentService $fulfillment,
    ) {}

    public function handle(Request $request): JsonResponse
    {
        $payload = $request->all();
        $signature = (string) $request->header('X-Callback-Signature', '');

        if (! $this->gateway->verifySignature($payload, $signature)) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid signature',
            ], 403);
        }

        $transaction = Transaction::query()
            ->where('invoice_id', $payload['merchant_ref'] ?? '')
            ->first();

        if ($transaction === null) {
            return response()->json([
                'success' => false,
                'message' => 'Transaction not found',
            ], 404);
        }

        $newStatus = $this->gateway->mapStatus((string) ($payload['status'] ?? ''));

        // Webhook berikutnya setelah paid (mis. refund) tidak boleh menurunkan status.
        if ($transaction->status !== 'pending' && $newStatus === 'pending') {
            return response()->json(['success' => true]);
        }

        if ($newStatus === 'paid' && $transaction->status === 'pending') {
            $transaction->forceFill([
                'status' => 'paid',
                'paid_at' => now(),
            ])->save();

            $this->fulfillment->fulfill($transaction->refresh());
        } elseif (in_array($newStatus, ['failed', 'expired'], true)) {
            if ($transaction->status === 'pending') {
                $transaction->forceFill(['status' => $newStatus])->save();
            }
        }

        return response()->json(['success' => true]);
    }
}
