<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CapcutAccount;
use App\Models\CapcutPartnerOrder;
use App\Support\Credentials;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * API internal CapCut Pro untuk bot partner Telegram (Murthehelp).
 */
class InternalCapcutController extends Controller
{
    /**
     * Cek jumlah stok akun CapCut yang siap dijual.
     * GET /api/internal/capcut/stock
     */
    public function stock(Request $request): JsonResponse
    {
        $this->authorizeInternal($request);

        $this->releaseStaleReservations();

        $ready = CapcutAccount::where('status', 'ready')->count();
        $sold = CapcutAccount::where('status', 'sold')->count();
        $total = CapcutAccount::count();

        $requestedPackage = $request->query('package_id');
        if ($requestedPackage === '1m') {
            return response()->json([
                'status' => 'success',
                'package_id' => '1m',
                'ready_stock' => $ready,
            ]);
        }

        return response()->json([
            'status' => 'success',
            'ready_stock' => $ready,
            'packages' => [
                '1m' => [
                    'package_id' => '1m',
                    'name' => 'CapCut Pro 1 Bulan',
                    'ready_stock' => $ready,
                ],
            ],
            'total_sold' => $sold,
            'total_accounts' => $total,
        ]);
    }

    /**
     * Katalog paket CapCut Pro beserta durasi, garansi, harga, dan aturan expiry.
     * GET /api/internal/capcut/packages
     */
    public function packages(Request $request): JsonResponse
    {
        $this->authorizeInternal($request);

        $catalog = collect(config('services.capcut.packages', []))
            ->map(fn (array $package, string $id) => array_merge(['id' => $id], $package))
            ->values();

        return response()->json([
            'status' => 'success',
            'currency' => config('services.capcut.currency', 'IDR'),
            'ttl_minutes' => config('services.capcut.ttl'),
            'packages' => $catalog,
        ]);
    }

    /**
     * Reservasi akun untuk satu order_id (idempotent).
     * POST /api/internal/capcut/reservations
     */
    public function reserve(Request $request): JsonResponse
    {
        $this->authorizeInternal($request);

        $validated = $request->validate([
            'order_id' => ['required', 'string', 'max:100'],
            'package_id' => ['required', 'string', Rule::in(array_keys(config('services.capcut.packages', [])))],
            'buyer_reference' => ['required', 'string', 'max:50', 'regex:/^[0-9]+$/'],
            'buyer_username' => ['nullable', 'string', 'max:100'],
            'ttl_minutes' => ['nullable', 'integer'],
        ]);

        $ttl = $this->clampTtl($validated['ttl_minutes'] ?? null);

        $payload = [
            'order_id' => trim($validated['order_id']),
            'package_id' => $validated['package_id'],
            'buyer_reference' => $validated['buyer_reference'],
            'buyer_username' => $validated['buyer_username'] ?? null,
            'ttl_minutes' => $ttl,
        ];
        $hash = hash('sha256', json_encode($payload));

        return DB::transaction(function () use ($payload, $hash, $ttl) {
            $existing = CapcutPartnerOrder::where('order_id', $payload['order_id'])
                ->lockForUpdate()
                ->first();

            if ($existing) {
                if (! hash_equals($existing->payload_hash, $hash)) {
                    return $this->error('IDEMPOTENCY_CONFLICT', 'order_id ini sudah dipakai dengan payload berbeda.', 409);
                }

                return response()->json([
                    'status' => 'success',
                    'duplicated' => true,
                    'data' => $existing->toApiPayload(),
                ]);
            }

            $this->releaseStaleReservations();

            $account = CapcutAccount::where('status', 'ready')
                ->orderBy('id')
                ->lockForUpdate()
                ->first();

            if (! $account) {
                return $this->error('OUT_OF_STOCK', 'Stok akun CapCut Pro sedang habis.', 409);
            }

            $package = CapcutPartnerOrder::package($payload['package_id']);

            $order = CapcutPartnerOrder::create([
                'order_id' => $payload['order_id'],
                'reservation_id' => (string) Str::uuid(),
                'payload_hash' => $hash,
                'package_id' => $payload['package_id'],
                'buyer_reference' => $payload['buyer_reference'],
                'buyer_username' => $payload['buyer_username'],
                'ttl_minutes' => $ttl,
                'status' => 'reserved',
                'capcut_account_id' => $account->id,
                'price' => $package['price'] ?? 0,
                'currency' => $package['currency'] ?? 'IDR',
                'reserved_until' => now()->addMinutes($ttl),
                'otp_token' => Str::random(48),
            ]);

            $account->forceFill(['status' => 'reserved', 'channel' => 'telegram'])->save();

            return response()->json([
                'status' => 'success',
                'data' => $order->toApiPayload(),
            ], 201);
        });
    }

    /**
     * Inkuiri status order (idempotent, pemulihan pasca-timeout/restart).
     * GET /api/internal/capcut/orders/{order_id}
     */
    public function order(Request $request, string $orderId): JsonResponse
    {
        $this->authorizeInternal($request);

        $this->releaseStaleReservations();

        $order = CapcutPartnerOrder::where('order_id', $orderId)->first();

        if (! $order) {
            return $this->error('ORDER_NOT_FOUND', 'Order ID tidak ditemukan.', 404);
        }

        $withCredentials = ($order->status === 'completed');

        return response()->json([
            'status' => 'success',
            'data' => $order->toApiPayload($withCredentials),
        ]);
    }

    /**
     * Klaim akun setelah pembayaran terverifikasi di bot.
     * POST /api/internal/capcut/claim
     */
    public function claim(Request $request): JsonResponse
    {
        $this->authorizeInternal($request);

        $validated = $request->validate([
            'order_id' => ['required', 'string', 'max:100'],
            'reservation_id' => ['nullable', 'string', 'max:36'],
            'payment_reference' => ['required', 'string', 'max:150'],
            'amount' => ['required', 'numeric', 'min:1'],
            'currency' => ['required', 'string', 'size:3'],
            'verified_at' => ['required', 'date'],
        ]);

        $payment = [
            'payment_reference' => $validated['payment_reference'],
            'payment_amount' => $validated['amount'],
            'payment_currency' => strtoupper($validated['currency']),
            'verified_at' => \Carbon\Carbon::parse($validated['verified_at'])->setTimezone('UTC'),
        ];

        return DB::transaction(function () use ($validated, $payment) {
            $order = CapcutPartnerOrder::where('order_id', $validated['order_id'])
                ->lockForUpdate()
                ->first();

            if (! $order) {
                return $this->error('ORDER_NOT_FOUND', 'order_id tidak ditemukan.', 404);
            }

            if (! empty($validated['reservation_id']) && $order->reservation_id !== $validated['reservation_id']) {
                return $this->error('RESERVATION_MISMATCH', 'reservation_id tidak cocok dengan order_id.', 409);
            }

            // Idempotensi klaim berulang dengan payload sama
            if ($order->status === 'completed') {
                if ($order->payment_reference !== $payment['payment_reference']) {
                    return $this->error('PAYMENT_REFERENCE_MISMATCH', 'Order sudah diklaim dengan payment_reference berbeda.', 409);
                }

                return response()->json([
                    'status' => 'success',
                    'duplicated' => true,
                    'data' => $order->toApiPayload(true),
                ]);
            }

            $duplicatePayment = CapcutPartnerOrder::where('payment_reference', $payment['payment_reference'])
                ->where('id', '!=', $order->id)
                ->exists();

            if ($duplicatePayment) {
                return $this->error('DUPLICATE_PAYMENT_REFERENCE', 'payment_reference ini sudah dipakai untuk order lain.', 409);
            }

            if ((float) $payment['payment_amount'] !== (float) $order->price || $payment['payment_currency'] !== $order->currency) {
                return $this->error('AMOUNT_CURRENCY_MISMATCH', 'Nominal atau mata uang pembayaran tidak cocok dengan harga paket.', 422);
            }

            $nowWib = now()->setTimezone('Asia/Jakarta');
            $reservedUntilWib = $order->reserved_until ? $order->reserved_until->setTimezone('Asia/Jakarta') : null;
            $verifiedAtWib = $payment['verified_at']->copy()->setTimezone('Asia/Jakarta');

            if ($reservedUntilWib && $verifiedAtWib->gt($reservedUntilWib)) {
                return $this->flagReviewRequired($order, $payment, 'Pembeli membayar setelah masa reservasi habis.');
            }

            $account = CapcutAccount::whereKey($order->capcut_account_id)
                ->lockForUpdate()
                ->first();

            if (! $account) {
                return $this->flagReviewRequired($order, $payment, 'Akun CapCut yang direservasi tidak ditemukan.');
            }

            if ($order->status === 'expired' || $order->status === 'cancelled') {
                if ($account->status !== 'ready') {
                    return $this->flagReviewRequired($order, $payment, 'Reservasi sudah batal dan akun telah dialokasikan ke pembeli lain.');
                }
            }

            $package = CapcutPartnerOrder::package($order->package_id);
            $durationDays = $package['duration_days'] ?? 30;
            $warrantyDays = $package['warranty_days'] ?? 30;

            $startDate = $nowWib->copy()->startOfDay();
            $endDate = $startDate->copy()->addDays($durationDays);
            $warrantyUntil = $startDate->copy()->addDays($warrantyDays);

            $order->forceFill($payment + [
                'status' => 'completed',
                'claimed_at' => now(),
                'start_date' => $startDate->toDateString(),
                'end_date' => $endDate->toDateString(),
                'warranty_until' => $warrantyUntil->toDateString(),
            ])->save();

            $account->forceFill([
                'status' => 'sold',
                'channel' => 'telegram',
                'sold_at' => now(),
            ])->save();

            return response()->json([
                'status' => 'success',
                'data' => $order->toApiPayload(true),
            ]);
        });
    }

    /**
     * Batalkan reservasi sebelum pembayaran terverifikasi.
     * POST /api/internal/capcut/cancel-reservation
     */
    public function cancelReservation(Request $request): JsonResponse
    {
        $this->authorizeInternal($request);

        $validated = $request->validate([
            'order_id' => ['required', 'string', 'max:100'],
            'reservation_id' => ['nullable', 'string', 'max:36'],
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        return DB::transaction(function () use ($validated) {
            $order = CapcutPartnerOrder::where('order_id', $validated['order_id'])
                ->lockForUpdate()
                ->first();

            if (! $order) {
                return $this->error('ORDER_NOT_FOUND', 'Order ID tidak ditemukan.', 404);
            }

            if (! empty($validated['reservation_id']) && $order->reservation_id !== $validated['reservation_id']) {
                return $this->error('RESERVATION_MISMATCH', 'reservation_id tidak cocok dengan order_id.', 409);
            }

            if ($order->status === 'completed') {
                return $this->error('ORDER_ALREADY_COMPLETED', 'Order yang sudah lunas tidak dapat dibatalkan melalui API ini.', 409);
            }

            if ($order->status === 'cancelled') {
                return response()->json([
                    'status' => 'success',
                    'data' => $order->toApiPayload(),
                ]);
            }

            $account = CapcutAccount::whereKey($order->capcut_account_id)
                ->lockForUpdate()
                ->first();

            if ($account && $account->status === 'reserved') {
                $account->forceFill(['status' => 'ready', 'channel' => null])->save();
            }

            $order->forceFill([
                'status' => 'cancelled',
                'cancelled_at' => now(),
            ])->save();

            return response()->json([
                'status' => 'success',
                'data' => $order->toApiPayload(),
            ]);
        });
    }

    /**
     * Halaman / data info akun untuk pembeli via token aman.
     * GET /api/internal/capcut/otp/{token}
     */
    public function otp(string $token): JsonResponse
    {
        $order = CapcutPartnerOrder::where('otp_token', $token)
            ->with('account')
            ->first();

        if (! $order) {
            return $this->error('INVALID_TOKEN', 'Tautan akses tidak valid.', 404);
        }

        if ($order->status !== 'completed') {
            return $this->error('ORDER_NOT_COMPLETED', 'Pesanan belum selesai atau pembayaran belum diverifikasi.', 403);
        }

        if ($order->end_date && now()->setTimezone('Asia/Jakarta')->startOfDay()->gt($order->end_date)) {
            return $this->error('ACCESS_EXPIRED', 'Masa aktif langganan akun ini sudah berakhir.', 403);
        }

        return response()->json([
            'status' => 'success',
            'data' => [
                'order_id' => $order->order_id,
                'reservation_id' => $order->reservation_id,
                'stock_id' => $order->capcut_account_id,
                'email' => $order->account?->email,
                'password' => $order->account?->password,
                'timezone' => 'Asia/Jakarta',
                'start_date' => $order->start_date?->toDateString(),
                'end_date' => $order->end_date?->toDateString(),
                'warranty_until' => $order->warranty_until?->toDateString(),
            ],
        ]);
    }

    protected function releaseStaleReservations(): int
    {
        return DB::transaction(function () {
            $stale = CapcutPartnerOrder::where('status', 'reserved')
                ->whereNotNull('reserved_until')
                ->where('reserved_until', '<', now())
                ->lockForUpdate()
                ->get();

            foreach ($stale as $order) {
                $account = CapcutAccount::whereKey($order->capcut_account_id)
                    ->lockForUpdate()
                    ->first();

                if ($account && $account->status === 'reserved') {
                    $account->forceFill(['status' => 'ready', 'channel' => null])->save();
                }

                $order->forceFill(['status' => 'expired'])->save();
            }

            return $stale->count();
        });
    }

    protected function flagReviewRequired(CapcutPartnerOrder $order, array $payment, string $reason): JsonResponse
    {
        $order->forceFill($payment + ['status' => 'review_required'])->save();

        return $this->error('PAYMENT_REVIEW_REQUIRED', $reason.' Order ditandai review_required untuk rekonsiliasi manual.', 409);
    }

    protected function clampTtl(?int $ttl): int
    {
        $bounds = config('services.capcut.ttl');
        $ttl ??= $bounds['default'];

        return (int) max($bounds['min'], min($bounds['max'], $ttl));
    }

    protected function error(string $code, string $message, int $status): JsonResponse
    {
        return response()->json([
            'status' => 'error',
            'code' => $code,
            'message' => $message,
        ], $status);
    }

    protected function authorizeInternal(Request $request): void
    {
        $configuredKey = (string) Credentials::get('internal_api_key', config('services.internal_api_key'));

        if (empty($configuredKey)) {
            abort(500, 'Internal API key belum dikonfigurasi di server.');
        }

        $providedKey = (string) ($request->header('X-Internal-Key')
            ?? $request->bearerToken()
            ?? $request->input('api_key')
            ?? '');

        if (empty($providedKey) || ! hash_equals($configuredKey, $providedKey)) {
            abort(401, 'Unauthorized internal access.');
        }
    }
}
