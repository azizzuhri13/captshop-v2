<?php

namespace App\Support;

use App\Models\PaymentChannel;
use App\Services\Payment\TripayClient;

/**
 * Satu-satunya sumber kebenaran gateway pembayaran yang benar-benar melayani
 * pesanan baru. Binding container dan katalog channel publik WAJIB memakai
 * kelas ini agar keduanya tidak pernah berbeda pendapat.
 *
 * Sumber keputusan (berurutan):
 *  1. site setting `payment_gateway` — saklar eksplisit dari panel admin.
 *     Tripay hanya dipilih bila kredensial + channel + callback benar-benar
 *     siap (lihat tripayReadiness()). Jadi mengisi kredensial saja TIDAK
 *     mengaktifkan Tripay.
 *  2. config('payment.default') + config('payment.tripay.enabled') —
 *     jalur lama yang sengaja tetap literal 'belibayar'. Env
 *     PAYMENT_GATEWAY=tripay sisa host lama tidak pernah dibaca.
 */
class PaymentGateway
{
    public const BELIBAYAR = 'belibayar';

    public const TRIPAY = 'tripay';

    /**
     * Gateway yang boleh dipakai. Nilai lain diperlakukan sebagai tidak
     * didukung, bukan sebagai gateway baru.
     */
    public const SUPPORTED = [self::BELIBAYAR, self::TRIPAY];

    /** Key site setting untuk saklar gateway eksplisit admin. */
    public const SETTING_KEY = 'payment_gateway';

    /** Callback Tripay yang sah — host produksi Laravel, bukan app Next.js. */
    public const TRIPAY_CALLBACK_URL = 'https://captshop.id/webhook/tripay';

    /**
     * Gateway efektif untuk pesanan baru.
     *
     * Fail-closed: nilai kosong, tidak dikenal, atau Tripay yang belum siap
     * selalu jatuh ke Belibayar — jangan pernah menyembunyikan channel
     * Belibayar karena salah konfigurasi.
     */
    public static function effective(): string
    {
        $stored = self::storedChoice();

        if ($stored !== null) {
            // Saklar admin menang atas config. Tripay tetap butuh readiness
            // pada setiap request, bukan hanya saat saklar ditekan.
            return $stored === self::TRIPAY && self::tripayReady()
                ? self::TRIPAY
                : self::BELIBAYAR;
        }

        $configured = strtolower(trim((string) config('payment.default', self::BELIBAYAR)));

        if ($configured === self::TRIPAY && config('payment.tripay.enabled') === true) {
            return self::TRIPAY;
        }

        return self::BELIBAYAR;
    }

    public static function isTripay(): bool
    {
        return self::effective() === self::TRIPAY;
    }

    public static function isBelibayar(): bool
    {
        return self::effective() === self::BELIBAYAR;
    }

    /**
     * Nilai saklar yang tersimpan di site_settings, atau null bila belum
     * pernah diatur. Nilai di luar daftar resmi dikembalikan apa adanya agar
     * effective() memperlakukannya sebagai Belibayar (fail-closed), bukan
     * sebagai gateway baru.
     */
    public static function storedChoice(): ?string
    {
        $stored = Credentials::get(self::SETTING_KEY);

        if ($stored === null) {
            return null;
        }

        $stored = strtolower(trim($stored));

        return $stored === '' ? null : $stored;
    }

    /**
     * Apakah Tripay layak melayani pesanan baru sekarang juga.
     *
     * Readiness = kredensial valid (mode sandbox/live) + minimal satu channel
     * Tripay aktif + callback URL resmi. Gagal baca DB (mis. saat migrate)
     * diperlakukan sebagai belum siap.
     */
    public static function tripayReady(): bool
    {
        return self::tripayReadiness()['ready'];
    }

    /**
     * @return array{ready: bool, missing: list<string>}
     */
    public static function tripayReadiness(): array
    {
        $missing = [];

        if (! self::tripayCredentialsReady()) {
            $missing[] = 'Kredensial Tripay (API Key, Private Key, Merchant Code, dan mode sandbox/live) belum lengkap atau tidak valid.';
        }

        if (! self::tripayChannelsReady()) {
            $missing[] = 'Belum ada channel Tripay yang aktif di menu Channel Pembayaran.';
        }

        if ((string) config('payment.tripay.callback_url') !== self::TRIPAY_CALLBACK_URL) {
            $missing[] = 'Callback URL Tripay harus '.self::TRIPAY_CALLBACK_URL.' (host captshop.id).';
        }

        return ['ready' => $missing === [], 'missing' => $missing];
    }

    /**
     * Validasi kredensial memakai aturan yang sama dengan TripayClient agar
     * panel dan client tidak pernah berbeda pendapat.
     */
    private static function tripayCredentialsReady(): bool
    {
        try {
            return app(TripayClient::class)->isConfigured();
        } catch (\Throwable) {
            return false;
        }
    }

    private static function tripayChannelsReady(): bool
    {
        try {
            $map = config('payment.tripay.channels', []);
            $codes = is_array($map) ? array_keys($map) : [];

            if ($codes === []) {
                return false;
            }

            return PaymentChannel::query()
                ->where('gateway', self::TRIPAY)
                ->whereIn('code', $codes)
                ->where('is_active', true)
                ->exists();
        } catch (\Throwable) {
            return false;
        }
    }
}