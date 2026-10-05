<?php

namespace App\Providers;

use App\Models\Admin;
use App\Services\Payment\PaymentGatewayInterface;
use App\Services\Payment\TripayService;
use App\Services\Supplier\DigiflazzService;
use App\Services\Supplier\SupplierInterface;
use Illuminate\Support\Facades\Gate;
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
            'transactions.view',
        ],
        'operator' => [
            'products.manage', 'product-items.manage', 'coupons.manage',
            'transactions.view',
        ],
        'cs' => [
            'transactions.view',
        ],
    ];

    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(PaymentGatewayInterface::class, function ($app) {
            return match (config('payment.default')) {
                'tripay' => $app->make(TripayService::class),
                default => $app->make(TripayService::class),
            };
        });

        $this->app->bind(SupplierInterface::class, function ($app) {
            return match (config('supplier.default')) {
                'digiflazz' => $app->make(DigiflazzService::class),
                default => $app->make(DigiflazzService::class),
            };
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
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
    }
}
