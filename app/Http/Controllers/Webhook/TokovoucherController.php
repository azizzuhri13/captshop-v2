<?php

namespace App\Http\Controllers\Webhook;

use App\Http\Controllers\Controller;
use App\Models\Transaction;
use App\Services\Notification\NotificationService;
use App\Support\Credentials;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class TokovoucherController extends Controller
{
    public function __construct(
        protected NotificationService $notifications,
    ) {}

    public function handle(Request $request): JsonResponse
    {
        $payload = $request->json()->all();
        $refId = (string) ($payload['ref_id'] ?? '');

        if ($refId === '') {
            return response()->json(['status' => 'error', 'message' => 'Missing ref_id'], 400);
        }

        $memberCode = (string) Credentials::get('tokovoucher_member_code', config('supplier.tokovoucher.member_code'));
        $secret = (string) Credentials::get('tokovoucher_secret_key', config('supplier.tokovoucher.secret_key'));

        $authHeader = (string) $request->header('X-TokoVoucher-Authorization', '');
        $expectedAuth = md5($memberCode.':'.$secret.':'.$refId);

        if ($authHeader === '' || ! hash_equals($expectedAuth, $authHeader)) {
            Log::warning('Tokovoucher webhook unauthorized attempt', [
                'ref_id' => $refId,
                'ip' => $request->ip(),
            ]);

            return response()->json(['status' => 'unauthorized'], 403);
        }

        $statusStr = strtolower((string) ($payload['status'] ?? ''));
        $sn = (string) ($payload['sn'] ?? '');
        $trxId = (string) ($payload['trx_id'] ?? '');

        $transaction = null;

        DB::transaction(function () use ($refId, $statusStr, $sn, $trxId, $payload, &$transaction) {
            $trx = Transaction::query()->where('invoice_id', $refId)->lockForUpdate()->first();

            if (! $trx) {
                return;
            }

            if ($statusStr === 'sukses' || $statusStr === 'success') {
                $trx->forceFill([
                    'status' => 'completed',
                    'supplier_ref' => $trxId ?: $trx->supplier_ref,
                    'supplier_sn' => $sn ?: $trx->supplier_sn,
                    'supplier_response' => json_encode($payload),
                    'completed_at' => now(),
                ])->save();

                $transaction = $trx;
            } elseif ($statusStr === 'gagal' || $statusStr === 'failed') {
                $trx->forceFill([
                    'status' => 'failed',
                    'supplier_response' => json_encode($payload),
                ])->save();
            }
        });

        if ($transaction !== null) {
            try {
                $this->notifications->orderCompleted($transaction);
            } catch (\Throwable $e) {
                Log::error('Gagal mengirim notifikasi Tokovoucher order completed: '.$e->getMessage());
            }
        }

        return response()->json(['status' => 'ok']);
    }
}
