<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SecurityHeaders
{
    /**
     * Header keamanan standar untuk semua respons.
     *
     * CSP hanya mengizinkan sumber yang dipakai aplikasi: Tailwind/Vite dari
     * origin sendiri, font Bunny, dan gambar dari mana saja (ikon/banner
     * produk diisi admin via URL eksternal). unsafe-inline diperlukan
     * karena Alpine.js memasang handler inline di atribut elemen.
     *
     * Saat Vite dev server aktif, origin-nya ikut diizinkan (lihat
     * contentSecurityPolicy) karena aset dev dilayani dari port berbeda.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), payment=()');
        $response->headers->set('Content-Security-Policy', $this->contentSecurityPolicy());

        // HSTS hanya relevan saat benar-benar dilayani via HTTPS.
        if ($request->secure()) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        return $response;
    }

    /**
     * Susun CSP.
     *
     * 'unsafe-eval' selalu disertakan: Alpine mengevaluasi ekspresi atribut
     * (x-data, :class, :style, @click) lewat new Function() di runtime, dan
     * itu terjadi baik pada aset hasil build maupun aset dev server. Tanpa
     * directive ini seluruh komponen interaktif mati (carousel, pemilih
     * nominal, menu mobile) tanpa pesan error yang terlihat.
     *
     * Origin Vite dev server ditambahkan hanya saat dev berjalan, karena
     * aset dev (CSS, JS, websocket HMR) dilayani dari port berbeda sehingga
     * tertolak oleh 'self'.
     *
     * Menghapus 'unsafe-eval' berarti beralih ke @alpinejs/csp, yang
     * mengharuskan semua ekspresi Alpine ditulis dalam subset sintaks
     * terbatas (tanpa operator kompleks / pemanggilan method).
     */
    protected function contentSecurityPolicy(): string
    {
        $script = "'self' 'unsafe-inline' 'unsafe-eval'";
        $style = "'self' 'unsafe-inline' https://fonts.bunny.net";
        $font = "'self' https://fonts.bunny.net";
        $connect = "'self'";

        if ($dev = $this->viteDevServerOrigin()) {
            $ws = preg_replace('/^http/', 'ws', $dev);
            $script .= " {$dev}";
            $style .= " {$dev}";
            $font .= " {$dev}";
            $connect .= " {$dev} {$ws}";
        }

        return "default-src 'self'; ".
            "script-src {$script}; ".
            "style-src {$style}; ".
            "font-src {$font}; ".
            "img-src 'self' data: https: http:; ".
            "connect-src {$connect}; ".
            "frame-ancestors 'none'; ".
            "base-uri 'self'; ".
            "form-action 'self'";
    }

    /**
     * Origin Vite dev server, dibaca dari public/hot yang ditulis Vite selama
     * `npm run dev` berjalan. Null bila aset dilayani dari hasil build —
     * sehingga CSP production tetap ketat.
     */
    protected function viteDevServerOrigin(): ?string
    {
        $hotFile = public_path('hot');

        if (! is_file($hotFile)) {
            return null;
        }

        $url = trim((string) file_get_contents($hotFile));

        return $url === '' ? null : rtrim($url, '/');
    }
}
