<?php

namespace App\Support;

use App\Models\SiteSetting;

/**
 * Kontak customer service publik.
 *
 * Sumber nilai: site_settings (dapat diubah admin dari panel Pengaturan),
 * dengan fallback config('site.contact.*') selama nilai tersimpan kosong.
 * Semua nilai keluar dari kelas ini sudah dinormalkan/difilter — nomor
 * WhatsApp direduksi menjadi digit, URL hanya diterima bila https dan
 * host-nya Instagram resmi — sehingga view tidak pernah merender input
 * mentah atau skema URL berbahaya (mis. javascript:).
 */
final class SupportContact
{
    private const SETTING_KEYS = [
        'whatsapp' => 'contact_whatsapp',
        'instagram' => 'contact_instagram',
        'email' => 'contact_email',
    ];

    /**
     * Nilai kontak siap render. Field yang tidak valid/tidak tersedia bernilai null.
     *
     * @return array{whatsapp: ?string, whatsapp_display: ?string, whatsapp_url: ?string,
     *               instagram: ?string, instagram_handle: ?string, email: ?string}
     */
    public static function resolve(): array
    {
        $stored = SiteSetting::query()
            ->whereIn('key', array_values(self::SETTING_KEYS))
            ->pluck('value', 'key');

        $whatsapp = self::normalizeWhatsapp(
            self::pick($stored->get(self::SETTING_KEYS['whatsapp']), config('site.contact.whatsapp'))
        );

        $instagram = self::sanitizeInstagramUrl(
            self::pick($stored->get(self::SETTING_KEYS['instagram']), config('site.contact.instagram'))
        );

        $email = self::sanitizeEmail(
            self::pick($stored->get(self::SETTING_KEYS['email']), config('site.contact.email'))
        );

        return [
            'whatsapp' => $whatsapp,
            'whatsapp_display' => $whatsapp === null ? null : self::formatWhatsapp($whatsapp),
            'whatsapp_url' => $whatsapp === null ? null : 'https://wa.me/'.$whatsapp,
            'instagram' => $instagram,
            'instagram_handle' => $instagram === null ? null : self::instagramHandle($instagram),
            'email' => $email,
        ];
    }

    /**
     * Ambil nilai tersimpan bila terisi, jika tidak pakai default config.
     */
    private static function pick(mixed $stored, mixed $default): ?string
    {
        foreach ([$stored, $default] as $candidate) {
            if (is_string($candidate) && trim($candidate) !== '') {
                return trim($candidate);
            }
        }

        return null;
    }

    /**
     * Nomor WhatsApp kanonik: digit saja, berawalan 62 (mis. 6282260677717).
     */
    public static function normalizeWhatsapp(?string $raw): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $raw) ?? '';

        if ($digits === '') {
            return null;
        }

        if (str_starts_with($digits, '00')) {
            $digits = substr($digits, 2);
        }

        if (str_starts_with($digits, '0')) {
            $digits = '62'.substr($digits, 1);
        } elseif (str_starts_with($digits, '8')) {
            $digits = '62'.$digits;
        }

        return preg_match('/^628\d{7,11}$/', $digits) === 1 ? $digits : null;
    }

    /**
     * Tampilan ramah baca, mis. +62 822-6067-7717.
     */
    public static function formatWhatsapp(string $digits): string
    {
        $national = substr($digits, 2);

        if (strlen($national) === 11) {
            return sprintf(
                '+62 %s-%s-%s',
                substr($national, 0, 3),
                substr($national, 3, 4),
                substr($national, 7)
            );
        }

        return '+62 '.implode('-', str_split($national, 4));
    }

    /**
     * Hanya URL Instagram https ke profil (bukan shortlink/redirect).
     */
    public static function sanitizeInstagramUrl(?string $raw): ?string
    {
        $raw = trim((string) $raw);

        if ($raw === '') {
            return null;
        }

        $parts = parse_url($raw);

        if ($parts === false || ! isset($parts['scheme'], $parts['host'])) {
            return null;
        }

        if (strtolower($parts['scheme']) !== 'https') {
            return null;
        }

        // Tolak userinfo/port/fragment: vektor classic untuk menyamarkan host.
        if (isset($parts['user']) || isset($parts['pass']) || isset($parts['port']) || isset($parts['fragment'])) {
            return null;
        }

        if (! in_array(strtolower($parts['host']), ['instagram.com', 'www.instagram.com'], true)) {
            return null;
        }

        if (preg_match('#^/[A-Za-z0-9._]{1,30}/?$#', $parts['path'] ?? '') !== 1) {
            return null;
        }

        if (isset($parts['query']) && preg_match('/^[A-Za-z0-9=&_%.+-]+$/', $parts['query']) !== 1) {
            return null;
        }

        return $raw;
    }

    public static function instagramHandle(string $url): ?string
    {
        $path = trim((string) parse_url($url, PHP_URL_PATH), '/');

        return $path === '' ? null : '@'.$path;
    }

    public static function sanitizeEmail(?string $raw): ?string
    {
        $email = trim((string) $raw);

        if ($email === '' || strlen($email) > 254) {
            return null;
        }

        return filter_var($email, FILTER_VALIDATE_EMAIL) === false ? null : $email;
    }
}
