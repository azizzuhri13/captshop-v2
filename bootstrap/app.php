<?php

use App\Http\Middleware\AdminAuth;
use App\Http\Middleware\AdminCan;
use App\Http\Middleware\CheckMaintenanceMode;
use App\Http\Middleware\SecurityHeaders;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function () {
            if (is_file($routes = dirname(__DIR__).'/routes/webhook.php')) {
                Route::middleware('api')->group($routes);
            }
        },
    )
    ->withMiddleware(function (Middleware $middleware) {
        // Saat berada di belakang reverse proxy (nginx, Cloudflare, dsb.),
        // IP asli visitor hanya terbaca dari header proxy. Tanpa trust, semua
        // request tampak berasal dari IP proxy — rate limiter, throttle login,
        // dan log audit menjadi tidak valid.
        $middleware->trustProxies(at: array_filter(array_map('trim', explode(',', (string) env('TRUSTED_PROXIES', '')))));

        $middleware->alias([
            'admin.auth' => AdminAuth::class,
            'admin.can' => AdminCan::class,
        ]);

        // Maintenance mode via site_settings (diatur admin panel); admin
        // panel & webhook tetap dapat diakses (di-exempt di middleware).
        $middleware->web(append: [
            CheckMaintenanceMode::class,
            SecurityHeaders::class,
        ]);

        // Route webhook di-load dengan grup 'api' — beri security headers
        // juga di sana (tanpa maintenance check: webhook tidak pernah
        // diblokir maintenance).
        $middleware->api(append: [
            SecurityHeaders::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();
