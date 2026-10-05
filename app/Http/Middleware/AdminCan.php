<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AdminCan
{
    /**
     * Batasi akses route admin berdasar Gate/ability role.
     * Dipakai sebagai alias middleware: ->middleware('admin.can:products.manage').
     */
    public function handle(Request $request, Closure $next, string $ability): Response
    {
        $admin = auth('admin')->user();

        if ($admin === null) {
            return redirect()->route('admin.login');
        }

        if (! $admin->can($ability)) {
            abort(403, 'Anda tidak memiliki izin untuk mengakses halaman ini.');
        }

        return $next($request);
    }
}
