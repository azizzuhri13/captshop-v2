<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DisneyAccount;
use App\Models\DisneyPartnerOrder;
use App\Support\Credentials;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * API internal Disney+ untuk bot partner Telegram (Murthehelp).
 */
class InternalDisneyController extends Controller
{
    /**
     * Cek jumlah stok akun Disney+ yang siap dijual.
     * GET /api/internal/disney/stock
     */
    public function stock(Request $request): JsonResponse
    {
        $this->authorizeInternal($request);

        $this->releaseStaleReservations();

        $ready = DisneyAccount::where('status', 'ready')->count();
        $sold = DisneyAccount::where('status', 'sold')->count();
        $total = DisneyAccount::count();

        $requestedPackage = $request->query('package_id');
        if ($requestedPackage === '1p1u_1m') {
            return response()->json([
                'status' => 'success',
                'package_id' => '1p1u_1m',
                'ready_stock' => $ready,
            ]);
        }

        return response()->json([
            'status' => 'success',
            'ready_stock' => $ready,
            'packages' => [
                '1p1u_1m' => [
                    'package_id' => '1p1u_1m',
                    'name' => 'Disney+ Hotstar 1 Bulan',
                    'ready_stock' => $ready,
                ],
            ],
            'total_sold' => $sold,
            'total_accounts' => $total,
        ]);
    }

    /**
     * Katalog paket Disney+ beserta durasi, garansi, harga, dan aturan expiry.
     * GET /api/internal/disney/packages
     */
    public function packages(Request $request): JsonResponse
    {
        $this->authorizeInternal($request);

        $catalog = collect(config('services.disney.packages', []))
            ->map(fn (array $package, string $id) => array_merge(['id' => $id], $package))
            ->values();

        return response()->json([
            'status' => 'success',
            'currency' => config('services.disney.currency', 'IDR'),
            'ttl_minutes' => config('services.disney.ttl'),
            'packages' => $catalog,
        ]);
    }

    /**
     * Reservasi akun untuk satu order_id (idempotent).
     * POST /api/internal/disney/reservations
     */
    public function reserve(Request $request): JsonResponse
    {
        $this->authorizeInternal($request);

        $validated = $request->validate([
            'order_id' => ['required', 'string', 'max:100'],
            'package_id' => ['required', 'string', Rule::in(array_keys(config('services.disney.packages', [])))],
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
            $existing = DisneyPartnerOrder::where('order_id', $payload['order_id'])
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

            $account = DisneyAccount::where('status', 'ready')
                ->orderBy('id')
                ->lockForUpdate()
                ->first();

            if (! $account) {
                return $this->error('OUT_OF_STOCK', 'Stok akun Disney+ sedang habis.', 409);
            }

            $package = DisneyPartnerOrder::package($payload['package_id']);

            $order = DisneyPartnerOrder::create([
                'order_id' => $payload['order_id'],
                'reservation_id' => (string) Str::uuid(),
                'payload_hash' => $hash,
                'package_id' => $payload['package_id'],
                'buyer_reference' => $payload['buyer_reference'],
                'buyer_username' => $payload['buyer_username'],
                'ttl_minutes' => $ttl,
                'status' => 'reserved',
                'disney_account_id' => $account->id,
                'price' => $package['price'] ?? 0,
                'currency' => $package['currency'] ?? 'IDR',
                'reserved_until' => now()->addMinutes($ttl),
                'otp_token' => Str::random(48),
            ]);

            $account->forceFill(['status' => 'reserved'])->save();

            return response()->json([
                'status' => 'success',
                'data' => $order->toApiPayload(),
            ], 201);
        });
    }

    /**
     * Inkuiri status order (idempotent, pemulihan pasca-timeout/restart).
     * GET /api/internal/disney/orders/{order_id}
     */
    public function order(Request $request, string $orderId): JsonResponse
    {
        $this->authorizeInternal($request);

        $this->releaseStaleReservations();

        $order = DisneyPartnerOrder::where('order_id', $orderId)->first();

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
     * POST /api/internal/disney/claim
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
            $order = DisneyPartnerOrder::where('order_id', $validated['order_id'])
                ->lockForUpdate()
                ->first();

            if (! $order) {
                return $this->error('ORDER_NOT_FOUND', 'order_id tidak ditemukan.', 404);
            }

            if (! empty($validated['reservation_id']) && $order->reservation_id !== $validated['reservation_id']) {
                return $this->error('RESERVATION_MISMATCH', 'reservation_id tidak cocok dengan order_id.', 409);
            }

            if ((float) $payment['payment_amount'] !== (float) $order->price) {
                return $this->error('PAYMENT_AMOUNT_MISMATCH', sprintf(
                    'Nominal pembayaran (%s) tidak sesuai dengan harga paket (%s).',
                    $payment['payment_amount'],
                    $order->price
                ), 422);
            }

            if (strtoupper($payment['payment_currency']) !== strtoupper($order->currency)) {
                return $this->error('PAYMENT_CURRENCY_MISMATCH', sprintf(
                    'Mata uang pembayaran (%s) tidak sesuai dengan paket (%s).',
                    $payment['payment_currency'],
                    $order->currency
                ), 422);
            }

            $duplicatedRef = DisneyPartnerOrder::where('payment_reference', $payment['payment_reference'])
                ->where('id', '!=', $order->id)
                ->exists();

            if ($duplicatedRef) {
                return $this->error('PAYMENT_REFERENCE_DUPLICATED', 'payment_reference ini sudah pernah digunakan untuk pesanan lain.', 409);
            }

            if ($order->status === 'completed') {
                $refMatch = ($order->payment_reference === $payment['payment_reference']);
                $amtMatch = ((float) $order->payment_amount === (float) $payment['payment_amount']);
                $currMatch = (strtoupper((string) $order->payment_currency) === strtoupper($payment['payment_currency']));

                if (! $refMatch || ! $amtMatch || ! $currMatch) {
                    return $this->error('IDEMPOTENCY_CONFLICT', 'Order sudah selesai dengan bukti pembayaran berbeda.', 409);
                }

                return response()->json([
                    'status' => 'success',
                    'duplicated' => true,
                    'data' => $order->toApiPayload(true),
                ]);
            }

            if ($order->status === 'cancelled') {
                return $this->error('ORDER_ALREADY_CANCELLED', 'Order ini sudah dibatalkan.', 409);
            }

            if ($order->status === 'expired' || ($order->reserved_until && $order->reserved_until->isPast())) {
                return $this->flagReviewRequired($order, $payment, 'Reservasi sudah kedaluwarsa/dilepas sebelum pembayaran terverifikasi.');
            }

            $account = DisneyAccount::whereKey($order->disney_account_id)
                ->lockForUpdate()
                ->first();

            if (! $account || $account->status !== 'reserved') {
                return $this->flagReviewRequired($order, $payment, 'Unit akun sudah tidak dalam penguncian reservasi order ini.');
            }

            $package = DisneyPartnerOrder::package($order->package_id);
            $duration = (int) ($package['duration_days'] ?? 30);
            $warranty = (int) ($package['warranty_days'] ?? 30);

            $startDate = now()->setTimezone('Asia/Jakarta')->startOfDay();
            $endDate = (clone $startDate)->addDays($duration);
            $warrantyUntil = (clone $startDate)->addDays($warranty);

            $order->forceFill(array_merge($payment, [
                'status' => 'completed',
                'claimed_at' => now(),
                'start_date' => $startDate->toDateString(),
                'end_date' => $endDate->toDateString(),
                'warranty_until' => $warrantyUntil->toDateString(),
            ]))->save();

            $account->forceFill([
                'status' => 'sold',
                'sold_at' => now(),
            ])->save();

            return response()->json([
                'status' => 'success',
                'data' => $order->toApiPayload(true),
            ]);
        });
    }

    /**
     * Batalkan reservasi (idempotent, melepaskan akun kembali ke ready).
     * POST /api/internal/disney/cancel-reservation
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
            $order = DisneyPartnerOrder::where('order_id', $validated['order_id'])
                ->lockForUpdate()
                ->first();

            if (! $order) {
                return $this->error('ORDER_NOT_FOUND', 'order_id tidak ditemukan.', 404);
            }

            if (! empty($validated['reservation_id']) && $order->reservation_id !== $validated['reservation_id']) {
                return $this->error('RESERVATION_MISMATCH', 'reservation_id tidak cocok dengan order_id.', 409);
            }

            if ($order->status === 'completed') {
                return $this->error('CANNOT_CANCEL_COMPLETED', 'Order yang sudah selesai tidak dapat dibatalkan.', 409);
            }

            if ($order->status === 'cancelled') {
                return response()->json([
                    'status' => 'success',
                    'duplicated' => true,
                    'data' => $order->toApiPayload(false),
                ]);
            }

            $account = DisneyAccount::whereKey($order->disney_account_id)
                ->lockForUpdate()
                ->first();

            if ($account && $account->status === 'reserved') {
                $account->forceFill(['status' => 'ready'])->save();
            }

            $order->forceFill([
                'status' => 'cancelled',
                'cancelled_at' => now(),
            ])->save();

            return response()->json([
                'status' => 'success',
                'data' => $order->toApiPayload(false),
            ]);
        });
    }

    /**
     * Endpoint publik pembeli untuk membaca OTP akun miliknya.
     * GET /api/internal/disney/otp/{token}
     */
    public function otp(Request $request, string $token): JsonResponse
    {
        $order = DisneyPartnerOrder::where('otp_token', $token)->first();

        if (! $order) {
            return $this->error('ORDER_NOT_FOUND', 'Token OTP tidak dikenal.', 404);
        }

        if ($order->status !== 'completed') {
            return $this->error('ORDER_NOT_COMPLETED', 'Akses OTP hanya tersedia setelah pesanan berstatus completed.', 403);
        }

        if ($order->end_date && now()->setTimezone('Asia/Jakarta')->startOfDay()->gt($order->end_date)) {
            return $this->error('ACCESS_EXPIRED', 'Masa aktif langganan akun ini sudah berakhir.', 403);
        }

        $emailOtp = $this->fetchLatestEmailOtp((string) $order->account?->email);

        return response()->json([
            'status' => 'success',
            'data' => [
                'order_id' => $order->order_id,
                'reservation_id' => $order->reservation_id,
                'stock_id' => $order->disney_account_id,
                'profile_id' => $order->disney_account_id,
                'profile' => $order->account?->profile_name,
                'profile_name' => $order->account?->profile_name,
                'profile_pin' => $order->account?->pin,
                'otp' => $order->account?->pin,
                'email' => $order->account?->email,
                'email_otp' => $emailOtp,
                'timezone' => 'Asia/Jakarta',
                'start_date' => $order->start_date?->toDateString(),
                'end_date' => $order->end_date?->toDateString(),
                'warranty_until' => $order->warranty_until?->toDateString(),
            ],
        ]);
    }

    protected function fetchLatestEmailOtp(?string $targetEmail): ?array
    {
        if (empty($targetEmail) || app()->environment('testing')) {
            return null;
        }

        $configFile = public_path('admin/config.json');
        if (! file_exists($configFile)) {
            return null;
        }

        try {
            $config = json_decode((string) file_get_contents($configFile), true);
            if (empty($config['imap_server']) || empty($config['email_username']) || empty($config['email_password'])) {
                return null;
            }

            if (! function_exists('imap_open')) {
                return null;
            }

            $mbox = @imap_open($config['imap_server'], $config['email_username'], $config['email_password'], OP_READONLY, 1, ['DISABLE_AUTHENTICATOR' => 'GSSAPI']);
            if (! $mbox) {
                return null;
            }

            $search = @imap_search($mbox, 'TO "'.$targetEmail.'"');
            if (! $search) {
                imap_close($mbox);
                return null;
            }

            $lastId = end($search);
            $header = @imap_headerinfo($mbox, $lastId);
            $body = @imap_body($mbox, $lastId);
            $subject = mb_decode_mimeheader($header->subject ?? '');

            imap_close($mbox);

            $code = null;
            if (preg_match('/\b[0-9]{4,6}\b/', $body, $matches)) {
                $code = $matches[0];
            }

            return [
                'code' => $code,
                'subject' => $subject,
                'from' => $header->fromaddress ?? 'Disney+',
                'date' => isset($header->date) ? date('c', strtotime($header->date)) : null,
            ];
        } catch (\Throwable $e) {
            Log::warning('fetchLatestEmailOtp Disney error: '.$e->getMessage());
            return null;
        }
    }

    protected function releaseStaleReservations(): int
    {
        return DB::transaction(function () {
            $stale = DisneyPartnerOrder::where('status', 'reserved')
                ->whereNotNull('reserved_until')
                ->where('reserved_until', '<', now())
                ->lockForUpdate()
                ->get();

            foreach ($stale as $order) {
                $account = DisneyAccount::whereKey($order->disney_account_id)
                    ->lockForUpdate()
                    ->first();

                if ($account && $account->status === 'reserved') {
                    $account->forceFill(['status' => 'ready'])->save();
                }

                $order->forceFill(['status' => 'expired'])->save();
            }

            return $stale->count();
        });
    }

    protected function flagReviewRequired(DisneyPartnerOrder $order, array $payment, string $reason): JsonResponse
    {
        $order->forceFill($payment + ['status' => 'review_required'])->save();

        return $this->error('PAYMENT_REVIEW_REQUIRED', $reason.' Order ditandai review_required untuk rekonsiliasi manual.', 409);
    }

    protected function clampTtl(?int $ttl): int
    {
        $bounds = config('services.disney.ttl');
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
        );

        if (! hash_equals($configuredKey, $providedKey)) {
            abort(401, 'Unauthorized: X-Internal-Key tidak valid.');
        }
    }
}
