<?php

namespace App\Services\Notification;

/**
 * Fail-closed allowlist for the private WhatsApp gateway base URL.
 *
 * Only http://127.0.0.1, http://localhost and http://[::1] are accepted, with
 * an optional port. Credentials, non-http schemes, paths, queries and
 * fragments are rejected so a misconfigured env var cannot leak the internal
 * key or message content to a foreign host.
 */
final class LocalGatewayUrl
{
    private const PATTERN = '/^http:\/\/(?<host>127\.0\.0\.1|localhost|\[::1\])(?::(?<port>[0-9]{1,5}))?\/?\z/i';

    /**
     * Return a normalised loopback base URL, or null when the value is not an
     * approved destination. Never returns partial/parsed input.
     */
    public static function sanitize(string $url): ?string
    {
        $url = trim($url);

        // Reject whitespace, control chars and backslashes anywhere: URL
        // parsers and HTTP clients can disagree about them (e.g. a newline or
        // "\" before an "@" would change the effective host).
        if ($url === '' || preg_match('/[\x00-\x20\x7f\\\\]/', $url) === 1) {
            return null;
        }

        if (preg_match(self::PATTERN, $url, $matches) !== 1) {
            return null;
        }

        $host = strtolower($matches['host']);
        $port = $matches['port'] ?? '';

        if ($port !== '') {
            $number = (int) $port;
            if ($number < 1 || $number > 65535) {
                return null;
            }
            $port = ':'.$number;
        }

        return 'http://'.$host.$port;
    }
}