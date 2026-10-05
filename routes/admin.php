<?php

use App\Http\Controllers\Admin\AdminAccountController;
use App\Http\Controllers\Admin\AuthController;
use App\Http\Controllers\Admin\BannerController;
use App\Http\Controllers\Admin\CategoryController;
use App\Http\Controllers\Admin\CouponController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\PaymentChannelController;
use App\Http\Controllers\Admin\ProductController;
use App\Http\Controllers\Admin\ProductItemController;
use App\Http\Controllers\Admin\SettingController;
use App\Http\Controllers\Admin\TransactionController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Admin Routes (prefix: /admin)
|--------------------------------------------------------------------------
*/

Route::prefix('admin')->name('admin.')->group(function () {
    // Guest admin.
    Route::get('login', [AuthController::class, 'loginForm'])->name('login');
    Route::post('login', [AuthController::class, 'login'])->middleware('throttle:10,1');
    Route::post('logout', [AuthController::class, 'logout'])->name('logout');

    // Authenticated admin.
    Route::middleware('admin.auth')->group(function () {
        Route::get('dashboard', [DashboardController::class, 'index'])->name('dashboard');

        Route::middleware('admin.can:categories.manage')->group(function () {
            Route::resource('categories', CategoryController::class)->except(['show']);
        });

        Route::middleware('admin.can:products.manage')->group(function () {
            Route::resource('products', ProductController::class)->except(['show']);
        });

        Route::middleware('admin.can:product-items.manage')->group(function () {
            Route::post('product-items/bulk-destroy', [ProductItemController::class, 'bulkDestroy'])->name('product-items.bulk-destroy');
            Route::post('product-items/bulk-toggle', [ProductItemController::class, 'bulkToggleActive'])->name('product-items.bulk-toggle');
            Route::resource('product-items', ProductItemController::class)->except(['show']);
        });

        // Transaksi: melihat untuk semua role (matrix); aksi finansial,
        // ekspor PII, dan retry hanya super_admin (default-deny via Gate).
        Route::middleware('admin.can:transactions.view')->group(function () {
            Route::get('transactions/export', [TransactionController::class, 'export'])->middleware('admin.can:transactions.export')->name('transactions.export');
            Route::post('transactions/{transaction}/retry-fulfillment', [TransactionController::class, 'retryFulfillment'])->middleware('admin.can:transactions.manage')->name('transactions.retry-fulfillment');
            Route::post('transactions/{transaction}/mark-completed', [TransactionController::class, 'markCompleted'])->middleware('admin.can:transactions.manage')->name('transactions.mark-completed');
            Route::resource('transactions', TransactionController::class)->only(['index', 'show']);
        });

        Route::middleware('admin.can:banners.manage')->group(function () {
            Route::resource('banners', BannerController::class)->except(['show']);
        });

        Route::middleware('admin.can:coupons.manage')->group(function () {
            Route::resource('coupons', CouponController::class)->except(['show']);
        });

        Route::middleware('admin.can:payment-channels.manage')->group(function () {
            Route::get('payment-channels', [PaymentChannelController::class, 'index'])->name('payment-channels.index');
            Route::get('payment-channels/{paymentChannel}/edit', [PaymentChannelController::class, 'edit'])->name('payment-channels.edit');
            Route::put('payment-channels/{paymentChannel}', [PaymentChannelController::class, 'update'])->name('payment-channels.update');
            Route::post('payment-channels/{paymentChannel}/toggle', [PaymentChannelController::class, 'toggle'])->name('payment-channels.toggle');
        });

        // Pengaturan berisi kredensial gateway: hanya super_admin (default-deny).
        Route::get('settings', [SettingController::class, 'edit'])->middleware('admin.can:admin.settings')->name('settings.edit');
        Route::put('settings', [SettingController::class, 'update'])->middleware('admin.can:admin.settings')->name('settings.update');

        // Manajemen akun admin: hanya super_admin (ability admin.accounts
        // tidak diberikan ke role lain di matriks Gate).
        Route::resource('accounts', AdminAccountController::class)->except(['show'])->names('accounts')->middleware('admin.can:admin.accounts');
    });
});
