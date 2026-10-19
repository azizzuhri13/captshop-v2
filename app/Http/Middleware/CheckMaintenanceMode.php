<?php

namespace App\Http\Middleware;

use App\Support\Credentials;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckMaintenanceMode
{
    /**
     * Prefix route yang tetap dapat diakses saat maintenance mode aktif:
     * panel admin (untuk mematikan mode), login rahasia, dan webhook payment gateway.
     */
    protected const EXEMPT_PREFIXES = ['admin', 'root-capt', 'webhook'];

    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->isMaintenanceEnabled() || $this->isExempt($request)) {
            return $next($request);
        }

        if ($request->expectsJson()) {
            return response()->json([
                'success' => false,
                'message' => 'Situs sedang dalam pemeliharaan. Silakan coba beberapa saat lagi.',
            ], 503);
        }

        return response()->view('maintenance', [], 503);
    }

    protected function isMaintenanceEnabled(): bool
    {
        try {
            return filter_var(Credentials::get('maintenance_mode'), FILTER_VALIDATE_BOOLEAN);
        } catch (\Throwable) {
            return false;
        }
    }

    protected function isExempt(Request $request): bool
    {
        foreach (self::EXEMPT_PREFIXES as $prefix) {
            if ($request->is($prefix) || $request->is($prefix.'/*')) {
                return true;
            }
        }

        return false;
    }
}
