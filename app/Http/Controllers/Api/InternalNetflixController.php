<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\NetflixAccount;
use App\Models\NetflixPartnerOrder;
use App\Support\Credentials;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * API internal untuk bot partner Telegram (Murthehelp).
 *
 * Kontrak: reservasi idempotent -> bukti pembayaran (/claim) -> kredensial akun.
 * Akun berkode stabil (stock_id/profile_id), tanggal hak dalam WIB, dan TTL
 * reservasi dipaksa server. Semua endpoint butuh header X-Internal-Key,
 * kecuali /otp/{token} yang kuncinya adalah token unik per order itu sendiri.
 */
class InternalNetflixController extends Controller
{
    /**
     * Cek jumlah stok akun Netflix yang siap dijual.
     * GET /api/internal/netflix/stock
     */
    public function stock(Request $request): JsonResponse
    {
        $this->authorizeInternal($request);

        // Reservasi kedaluwarsa dilepas lebih dulu agar stok yang dilaporkan jujur.
        $this->releaseStaleReservations();

        // 1p1u_1m: profil siap jual
        $ready = NetflixAccount::where('status', 'ready')->count();
        $sold = NetflixAccount::where('status', 'sold')->count();
        $total = NetflixAccount::count();

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
                    'name' => '1P1U 1 Bulan (1 Profil 1 User)',
                    'ready_stock' => $ready,
                ],
            ],
            'total_sold' => $sold,
            'total_accounts' => $total,
        ]);
    }

    /**
     * Katalog paket Netflix beserta durasi, garansi, harga, dan aturan expiry.
     * GET /api/internal/netflix/packages
     */
    public function packages(Request $request): JsonResponse
    {
        $this->authorizeInternal($request);

        $catalog = collect(config('services.netflix.packages', []))
            ->map(fn (array $package, string $id) => array_merge(['id' => $id], $package))
            ->values();

        return response()->json([
            'status' => 'success',
            'currency' => config('services.netflix.currency', 'IDR'),
            'ttl_minutes' => config('services.netflix.ttl'),
            'packages' => $catalog,
        ]);
    }

    /**
     * Reservasi akun untuk satu order_id (idempotent, TTL tidak diperpanjang
     * pada pengulangan payload yang sama).
     * POST /api/internal/netflix/reservations
     */
    public function reserve(Request $request): JsonResponse
    {
        $this->authorizeInternal($request);

        $validated = $request->validate([
            'order_id' => ['required', 'string', 'max:100'],
            'package_id' => ['required', 'string', Rule::in(array_keys(config('services.netflix.packages', [])))],
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
            $existing = NetflixPartnerOrder::where('order_id', $payload['order_id'])
                ->lockForUpdate()
                ->first();

            if ($existing) {
                if (! hash_equals($existing->payload_hash, $hash)) {
                    return $this->error('IDEMPOTENCY_CONFLICT', 'order_id ini sudah dipakai dengan payload berbeda.', 409);
                }

                // Reservasi berulang: objek yang sama, TTL tidak diperpanjang.
                return response()->json([
                    'status' => 'success',
                    'duplicated' => true,
                    'data' => $existing->toApiPayload(),
                ]);
            }

            $this->releaseStaleReservations();

            $account = NetflixAccount::where('status', 'ready')
                ->orderBy('id')
                ->lockForUpdate()
                ->first();

            if (! $account) {
                return $this->error('OUT_OF_STOCK', 'Stok akun Netflix sedang habis.', 409);
            }

            $package = NetflixPartnerOrder::package($payload['package_id']);

            $order = NetflixPartnerOrder::create([
                'order_id' => $payload['order_id'],
                'reservation_id' => (string) Str::uuid(),
                'payload_hash' => $hash,
                'package_id' => $payload['package_id'],
                'buyer_reference' => $payload['buyer_reference'],
                'buyer_username' => $payload['buyer_username'],
                'ttl_minutes' => $ttl,
                'status' => 'reserved',
                'netflix_account_id' => $account->id,
                'price' => $package['price'],
                'currency' => $package['currency'],
                'reserved_until' => now()->addMinutes($ttl),
                'otp_token' => Str::random(48),
            ]);

            $account->forceFill(['status' => 'reserved'])->save();
            $order->setRelation('account', $account);

            return response()->json([
                'status' => 'success',
                'data' => $order->toApiPayload(),
            ], 201);
        });
    }

    /**
     * Status order: memulihkan state setelah timeout/restart bot tanpa
     * membuat order baru. Kredensial ikut bila order sudah dibayar.
     * GET /api/internal/netflix/orders/{order_id}
     */
    public function order(Request $request, string $orderId): JsonResponse
    {
        $this->authorizeInternal($request);

        $this->releaseStaleReservations();

        $order = NetflixPartnerOrder::where('order_id', $orderId)->first();

        if (! $order) {
            return $this->error('ORDER_NOT_FOUND', 'Order tidak ditemukan.', 404);
        }

        return response()->json([
            'status' => 'success',
            'data' => $order->toApiPayload($order->status === 'completed'),
        ]);
    }

    /**
     * Bukti pembayaran + serah terima akun.
     * POST /api/internal/netflix/claim
     */
    public function claim(Request $request): JsonResponse
    {
        $this->authorizeInternal($request);

        $validated = $request->validate([
            'order_id' => ['required', 'string', 'max:100'],
            'reservation_id' => ['nullable', 'string', 'max:36'],
            'payment_reference' => ['required', 'string', 'max:150'],
            'amount' => ['required', 'numeric', 'min:0'],
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
            $order = NetflixPartnerOrder::where('order_id', $validated['order_id'])
                ->lockForUpdate()
                ->first();

            if (! $order) {
                return $this->error('ORDER_NOT_FOUND', 'Order tidak ditemukan.', 404);
            }

            if (! empty($validated['reservation_id']) && ! hash_equals($order->reservation_id, $validated['reservation_id'])) {
                return $this->error('RESERVATION_MISMATCH', 'reservation_id tidak cocok dengan order_id ini.', 409);
            }

            // Validasi nominal & mata uang harus cocok dengan snapshot reservasi
            if ((float) $payment['payment_amount'] !== (float) $order->price) {
                return $this->error('PAYMENT_AMOUNT_MISMATCH', "Nominal pembayaran ({$payment['payment_amount']}) tidak sesuai dengan harga paket ({$order->price}).", 422);
            }

            if ($payment['payment_currency'] !== $order->currency) {
                return $this->error('PAYMENT_CURRENCY_MISMATCH', "Mata uang ({$payment['payment_currency']}) tidak sesuai dengan paket ({$order->currency}).", 422);
            }

            // Satu payment_reference tidak boleh membayar dua order berbeda
            $duplicateRef = NetflixPartnerOrder::where('payment_reference', $payment['payment_reference'])
                ->where('id', '!=', $order->id)
                ->exists();
            if ($duplicateRef) {
                return $this->error('PAYMENT_REFERENCE_DUPLICATED', 'payment_reference ini sudah pernah digunakan untuk pesanan lain.', 409);
            }

            // Pengulangan klaim: idempotent, tanggal hak tidak bergeser, tolak jika payload berbeda
            if ($order->status === 'completed') {
                if ($order->payment_reference !== $payment['payment_reference'] || (float) $order->payment_amount !== (float) $payment['payment_amount']) {
                    return $this->error('IDEMPOTENCY_CONFLICT', 'Order sudah selesai dengan bukti pembayaran berbeda.', 409);
                }

                return response()->json([
                    'status' => 'success',
                    'duplicated' => true,
                    'data' => $order->toApiPayload(true),
                ]);
            }

            if ($order->status === 'cancelled') {
                return $this->error('ORDER_CANCELLED', 'Reservasi sudah dibatalkan; buat order baru.', 409);
            }

            // Pembayaran masuk setelah reservasi dilepas. Jangan alokasikan akun
            // lain dan jangan selesaikan otomatis: tandai untuk rekonsiliasi.
            if ($order->status !== 'reserved' || $order->reserved_until === null || $order->reserved_until->isPast()) {
                return $this->flagReviewRequired($order, $payment, 'Reservasi sudah kedaluwarsa/dilepas sebelum pembayaran terverifikasi.');
            }

            $account = NetflixAccount::whereKey($order->netflix_account_id)
                ->lockForUpdate()
                ->first();

            // Akun sudah dilepas proses lain: tetap butuh rekonsiliasi manual.
            if (! $account || $account->status !== 'reserved') {
                return $this->flagReviewRequired($order, $payment, 'Akun reservasi sudah tidak dalam status reserved.');
            }

            $package = NetflixPartnerOrder::package($order->package_id);
            $start = now()->setTimezone('Asia/Jakarta');

            // Tanggal hak memakai kalender WIB, bukan tanggal klaim berulang.
            $order->forceFill($payment + [
                'status' => 'completed',
                'claimed_at' => now(),
                'start_date' => $start->toDateString(),
                'end_date' => $start->copy()->addDays($package['duration_days'])->toDateString(),
                'warranty_until' => $start->copy()->addDays($package['warranty_days'])->toDateString(),
            ])->save();

            $account->forceFill(['status' => 'sold', 'sold_at' => now()])->save();

            $order->setRelation('account', $account);

            return response()->json([
                'status' => 'success',
                'data' => $order->toApiPayload(true),
            ]);
        });
    }

    /**
     * Pembatalan reservasi yang belum dibayar; aman diulang.
     * POST /api/internal/netflix/cancel-reservation
     */
    public function cancelReservation(Request $request): JsonResponse
    {
        $this->authorizeInternal($request);

        $validated = $request->validate([
            'order_id' => ['required', 'string', 'max:100'],
            'reservation_id' => ['nullable', 'string', 'max:36'],
        ]);

        return DB::transaction(function () use ($validated) {
            $order = NetflixPartnerOrder::where('order_id', $validated['order_id'])
                ->lockForUpdate()
                ->first();

            if (! $order) {
                return $this->error('ORDER_NOT_FOUND', 'Order tidak ditemukan.', 404);
            }

            if (! empty($validated['reservation_id']) && ! hash_equals($order->reservation_id, $validated['reservation_id'])) {
                return $this->error('RESERVATION_MISMATCH', 'reservation_id tidak cocok dengan order_id ini.', 409);
            }

            if ($order->status === 'completed') {
                return $this->error('ORDER_ALREADY_COMPLETED', 'Order sudah dibayar dan selesai; tidak bisa dibatalkan.', 409);
            }

            if ($order->status === 'review_required') {
                return $this->error('PAYMENT_REVIEW_REQUIRED', 'Order menunggu rekonsiliasi pembayaran; tidak bisa dilepas otomatis.', 409);
            }

            // Sudah dibatalkan / sudah dilepas kedaluwarsa: aman diulang.
            if (in_array($order->status, ['cancelled', 'expired'], true)) {
                return response()->json([
                    'status' => 'success',
                    'duplicated' => true,
                    'data' => $order->toApiPayload(),
                ]);
            }

            // Hanya reservasi yang masih memegang akun yang boleh dilepas.
            $account = NetflixAccount::whereKey($order->netflix_account_id)
                ->lockForUpdate()
                ->first();

            if ($account && $account->status === 'reserved') {
                $account->forceFill(['status' => 'ready'])->save();
            }

            $order->forceFill(['status' => 'cancelled', 'cancelled_at' => now()])->save();

            return response()->json([
                'status' => 'success',
                'data' => $order->toApiPayload(),
            ]);
        });
    }

    /**
     * Pembeli membaca OTP (PIN profil) miliknya sendiri. Otorisasi = token unik
     * per order, tanpa API key, sehingga isi order lain tidak pernah terjangkau.
     * GET /api/internal/netflix/otp/{token}
     */
    public function otp(Request $request, string $token): JsonResponse
    {
        $order = NetflixPartnerOrder::where('otp_token', $token)->first();

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
                'stock_id' => $order->netflix_account_id,
                'profile_id' => $order->netflix_account_id,
                'profile' => $order->account?->profile_name,
                'profile_name' => $order->account?->profile_name,
                'profile_pin' => $order->account?->pin,
                'otp' => $order->account?->pin, // kompatibilitas balik
                'email' => $order->account?->email,
                'email_otp' => $emailOtp,
                'timezone' => 'Asia/Jakarta',
                'start_date' => $order->start_date?->toDateString(),
                'end_date' => $order->end_date?->toDateString(),
                'warranty_until' => $order->warranty_until?->toDateString(),
            ],
        ]);
    }

    /**
     * Ambil kode OTP terbaru dari inbox IMAP khusus untuk email akun ini.
     * Mengembalikan null jika belum ada email OTP masuk atau jika koneksi gagal.
     */
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

            // Ekstrak kode angka 4-6 digit (Netflix sign-in / temporary access code)
            $code = null;
            if (preg_match('/\b[0-9]{4,6}\b/', $body, $matches)) {
                $code = $matches[0];
            }

            return [
                'code' => $code,
                'subject' => $subject,
                'from' => $header->fromaddress ?? 'Netflix',
                'date' => isset($header->date) ? date('c', strtotime($header->date)) : null,
            ];
        } catch (\Throwable $e) {
            Log::warning('fetchLatestEmailOtp error: '.$e->getMessage());
            return null;
        }
    }

    /**
     * Lepaskan akun dari reservasi yang lewat reserved_until (auto-expire).
     * Dipanggil pada setiap query stok/reservasi/status.
     */
    protected function releaseStaleReservations(): int
    {
        return DB::transaction(function () {
            $stale = NetflixPartnerOrder::where('status', 'reserved')
                ->whereNotNull('reserved_until')
                ->where('reserved_until', '<', now())
                ->lockForUpdate()
                ->get();

            foreach ($stale as $order) {
                $account = NetflixAccount::whereKey($order->netflix_account_id)
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

    /**
     * Tandai order butuh rekonsiliasi manual (pembayaran terlambat) dan simpan
     * bukti bayarnya. Akun tidak pernah dipindahkan ke order ini di sini.
     */
    protected function flagReviewRequired(NetflixPartnerOrder $order, array $payment, string $reason): JsonResponse
    {
        $order->forceFill($payment + ['status' => 'review_required'])->save();

        return $this->error('PAYMENT_REVIEW_REQUIRED', $reason.' Order ditandai review_required untuk rekonsiliasi manual.', 409);
    }

    /**
     * Batasi TTL sesuai batas server: min 5, max 30, default 15 menit.
     */
    protected function clampTtl(?int $ttl): int
    {
        $bounds = config('services.netflix.ttl');
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

    /**
     * Verifikasi token autentikasi internal.
     */
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
