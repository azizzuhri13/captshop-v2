<?php

namespace App\Providers;

use App\Models\Admin;
use App\Services\Payment\PaymentGatewayInterface;
use App\Services\Payment\BelibayarService;
use App\Services\Supplier\DigiflazzService;
use App\Services\Supplier\TokovoucherService;
use App\Services\Supplier\SupplierInterface;
use App\Services\GameValidator\GameValidatorInterface;
use App\Services\GameValidator\GameValidatorService;
use App\Support\Credentials;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Matriks izin per role admin. super_admin mewarisi semua izin.
     * Role lain (admin, operator, cs) hanya boleh mengakses yang eksplisit.
     *
     * Izin TIDAK tercantum di sini bersifat default-deny (hanya super_admin):
     * admin.settings, transactions.manage, transactions.export.
     */
    protected const ROLE_ABILITIES = [
        'admin' => [
            'categories.manage', 'products.manage', 'product-items.manage',
            'banners.manage', 'coupons.manage', 'payment-channels.manage',
            'articles.manage', 'transactions.view',
        ],
        'operator' => [
            'products.manage', 'product-items.manage', 'coupons.manage',
            'articles.manage', 'transactions.view',
        ],
        'cs' => [
            'transactions.view',
        ],
        'writer' => [
            'articles.manage',
        ],
    ];

    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(PaymentGatewayInterface::class, BelibayarService::class);
        $this->app->bind(GameValidatorInterface::class, GameValidatorService::class);

        $this->app->bind(SupplierInterface::class, function ($app) {
            $default = Credentials::get('supplier_default', config('supplier.default', 'digiflazz'));

            return match (strtolower((string) $default)) {
                'tokovoucher', 'apigames' => $app->make(TokovoucherService::class),
                default => $app->make(DigiflazzService::class),
            };
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        if ($this->app->environment('production', 'staging') || in_array(env('APP_ENV'), ['production', 'staging'], true)) {
            \Illuminate\Support\Facades\URL::forceScheme('https');
        }

        Gate::before(fn (Admin $admin) => $admin->isSuperAdmin() ? true : null);

        $abilities = [];

        foreach (self::ROLE_ABILITIES as $role => $roleAbilities) {
            foreach ($roleAbilities as $ability) {
                $abilities[$ability][] = $role;
            }
        }

        foreach ($abilities as $ability => $roles) {
            Gate::define($ability, fn (Admin $admin) => in_array($admin->role, $roles, true));
        }

        RateLimiter::for('orders', function (Request $request) {
            return Limit::perMinute(10)->by($request->ip());
        });
    }
}
