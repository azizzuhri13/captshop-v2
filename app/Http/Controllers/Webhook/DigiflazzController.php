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

class DigiflazzController extends Controller
{
    public function __construct(
        protected NotificationService $notifications,
    ) {}

    public function handle(Request $request): JsonResponse
    {
        // Tangani event ping untuk verifikasi webhook dari dashboard DigiFlazz
        if (strtolower((string) $request->header('X-Digiflazz-Event')) === 'ping') {
            return response()->json(['status' => 'ok', 'message' => 'pong']);
        }

        $secret = (string) Credentials::get('digiflazz_webhook_secret', config('supplier.digiflazz.webhook_secret'));

        if ($secret !== '') {
            $expected = 'sha1='.hash_hmac('sha1', $request->getContent(), $secret);
            $signature = (string) $request->header('X-Hub-Signature', '');

            if (! hash_equals($expected, $signature)) {
                Log::warning('DigiFlazz webhook unauthorized signature mismatch', [
                    'ip' => $request->ip(),
                ]);

                return response()->json(['status' => 'unauthorized'], 403);
            }
        }

        $payload = $request->json()->all();
        $data = $payload['data'] ?? [];
        $refId = (string) ($data['ref_id'] ?? '');

        if ($refId === '') {
            return response()->json(['status' => 'error', 'message' => 'Missing ref_id'], 400);
        }

        $statusStr = strtolower((string) ($data['status'] ?? ''));
        $sn = (string) ($data['sn'] ?? '');
        $message = (string) ($data['message'] ?? '');

        $transaction = null;

        DB::transaction(function () use ($refId, $statusStr, $sn, $data, &$transaction) {
            $trx = Transaction::query()->where('invoice_id', $refId)->lockForUpdate()->first();

            if (! $trx) {
                return;
            }

            if ($statusStr === 'sukses' || $statusStr === 'success') {
                $trx->forceFill([
                    'status' => 'completed',
                    'supplier_sn' => $sn ?: $trx->supplier_sn,
                    'supplier_response' => json_encode($data),
                    'completed_at' => now(),
                ])->save();

                $transaction = $trx;
            } elseif ($statusStr === 'gagal' || $statusStr === 'failed') {
                $trx->forceFill([
                    'status' => 'failed',
                    'supplier_response' => json_encode($data),
                ])->save();
            }
        });

        if ($transaction !== null) {
            try {
                $this->notifications->orderCompleted($transaction);
            } catch (\Throwable $e) {
                Log::error('Gagal mengirim notifikasi DigiFlazz order completed: '.$e->getMessage());
            }
        }

        return response()->json(['status' => 'ok']);
    }
}
