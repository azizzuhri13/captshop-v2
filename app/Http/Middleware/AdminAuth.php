<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AdminAuth
{
    /**
     * Pastikan request berasal dari admin yang terautentikasi (guard admin)
     * dan akunnya masih aktif.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $admin = auth('admin')->user();

        if ($admin === null) {
            return redirect()->route('admin.login');
        }

        if (! $admin->is_active) {
            auth('admin')->logout();

            return redirect()->route('admin.login')->withErrors(['email' => 'Akun Anda dinonaktifkan.']);
        }

        return $next($request);
    }
}
