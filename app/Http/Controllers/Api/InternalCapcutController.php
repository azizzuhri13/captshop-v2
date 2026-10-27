<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CapcutAccount;
use App\Models\CapcutPartnerOrder;
use App\Services\CapcutTierService;
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
        $this->expireUnsoldStock();

        $sellable = CapcutAccount::query()->sellable()->get(['id', 'created_at', 'status']);
        $tiers = collect(CapcutTierService::catalog())
            ->mapWithKeys(fn (array $row) => [$row['tier'] => $row + ['ready_stock' => 0]])
            ->all();

        foreach ($sellable as $account) {
            $info = $account->tierInfo();
            if (isset($tiers[$info['tier']])) {
                $tiers[$info['tier']]['ready_stock']++;
            }
        }

        $ready = $sellable->count();
        $lowestPrice = $sellable->isEmpty()
            ? null
            : $sellable->map(fn (CapcutAccount $account) => $account->tierInfo()['price'])->min();

        $sold = CapcutAccount::where('status', 'sold')->count();
        $total = CapcutAccount::count();

        $packagePayload = [
            'package_id' => '1m',
            'name' => 'CapCut Pro 1 Bulan',
            'ready_stock' => $ready,
            'lowest_price' => $lowestPrice,
            'tiers' => array_values($tiers),
        ];

        $requestedPackage = $request->query('package_id');
        if ($requestedPackage === '1m') {
            return response()->json([
                'status' => 'success',
                'package_id' => '1m',
                'ready_stock' => $ready,
                'lowest_price' => $lowestPrice,
                'tiers' => array_values($tiers),
            ]);
        }

        return response()->json([
            'status' => 'success',
            'ready_stock' => $ready,
            'lowest_price' => $lowestPrice,
            'tiers' => array_values($tiers),
            'packages' => [
                '1m' => $packagePayload,
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

        $this->expireUnsoldStock();

        $sellable = CapcutAccount::query()->sellable()->get(['id', 'created_at', 'status']);
        $tierCatalog = CapcutTierService::catalog();
        $lowestPrice = $sellable->isEmpty()
            ? null
            : $sellable->map(fn (CapcutAccount $account) => $account->tierInfo()['price'])->min();

        $catalog = collect(config('services.capcut.packages', []))
            ->map(function (array $package, string $id) use ($tierCatalog, $lowestPrice) {
                return array_merge(['id' => $id], $package, [
                    'tiers' => $tierCatalog,
                    'lowest_price' => $lowestPrice,
                ]);
            })
            ->values();

        return response()->json([
            'status' => 'success',
            'currency' => config('services.capcut.currency', 'IDR'),
            'ttl_minutes' => config('services.capcut.ttl'),
            'stock_validity_days' => CapcutTierService::validityDays(),
            'tiers' => $tierCatalog,
            'lowest_price' => $lowestPrice,
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
            $this->expireUnsoldStock();

            // Ambil akun termurah (paling tua) yang masih bisa dijual, lalu kunci
            // harga & sisa masa aktifnya ke order (snapshot, bukan katalog global).
            $account = CapcutAccount::query()
                ->sellable()
                ->orderBy('created_at')
                ->orderBy('id')
                ->lockForUpdate()
                ->first();

            if (! $account) {
                return $this->error('OUT_OF_STOCK', 'Stok akun CapCut Pro sedang habis.', 409);
            }

            $tier = $account->tierInfo();

            $order = CapcutPartnerOrder::create([
                'order_id' => $payload['order_id'],
                'reservation_id' => (string) Str::uuid(),
                'payload_hash' => $hash,
                'package_id' => $payload['package_id'],
                'tier' => $tier['tier'],
                'stock_expires_at' => $tier['valid_until'],
                'buyer_reference' => $payload['buyer_reference'],
                'buyer_username' => $payload['buyer_username'],
                'ttl_minutes' => $ttl,
                'status' => 'reserved',
                'capcut_account_id' => $account->id,
                'price' => $tier['price'],
                'currency' => $tier['currency'],
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
            // Kolom datetime tanpa offset disimpan apa adanya, jadi harus dinormalkan
            // ke zona aplikasi. Konversi ke UTC menggeser instant saat dibaca kembali.
            'verified_at' => \Carbon\Carbon::parse($validated['verified_at'])->setTimezone(config('app.timezone')),
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

            // Masa aktif mengikuti sisa masa berlaku akun stok (created_at + 30 hari),
            // bukan start_date + durasi katalog, karena akun diskon sudah berjalan
            // sebagian sebelum dijual.
            $stockExpiresAt = $order->stock_expires_at
                ? $order->stock_expires_at->copy()
                : $account->validUntil();

            if ($stockExpiresAt->copy()->endOfDay()->isPast()) {
                if ($account->status === 'reserved') {
                    $account->forceFill(['status' => 'expired', 'channel' => null, 'transaction_id' => null])->save();
                }

                return $this->flagReviewRequired($order, $payment, 'Masa berlaku akun stok CapCut sudah habis sebelum pembayaran diverifikasi (perlu refund).');
            }

            $startDate = $nowWib->copy()->startOfDay();
            $endDate = $stockExpiresAt->copy()->startOfDay();
            $warrantyUntil = $endDate->copy();

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
                    $account->forceFill([
                        'status' => $account->isExpired() ? 'expired' : 'ready',
                        'channel' => null,
                    ])->save();
                }

                $order->forceFill(['status' => 'expired'])->save();
            }

            return $stale->count();
        });
    }

    /** Tandai akun ready yang masa berlakunya sudah habis agar tidak bisa dijual. */
    protected function expireUnsoldStock(): int
    {
        return CapcutAccount::expireStale();
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
