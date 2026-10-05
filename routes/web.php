<?php

use App\Http\Controllers\Frontend\CouponController;
use App\Http\Controllers\Frontend\HomeController;
use App\Http\Controllers\Frontend\InvoiceController;
use App\Http\Controllers\Frontend\OrderController;
use App\Http\Controllers\Frontend\ProductController;
use App\Http\Controllers\Frontend\SitemapController;
use App\Http\Controllers\Frontend\TrackController;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;

// Route admin dimuat lebih dulu agar prefix /admin tidak tertangkap
// route catch-all /{category}/{slug} di bawah.
require __DIR__.'/admin.php';

Route::get('/', [HomeController::class, 'index'])->name('home');
Route::get('/search', [HomeController::class, 'search'])->name('search');

Route::get('/kategori/{slug}', [ProductController::class, 'category'])->name('category.show');
Route::get('/{category}/{slug}', [ProductController::class, 'show'])
    ->where('category', '[a-z0-9-]+')
    ->where('slug', '[a-z0-9-]+')
    ->name('product.show');

Route::post('/order', [OrderController::class, 'store'])
    ->middleware('throttle:orders')
    ->name('order.store');

Route::post('/coupon/check', [CouponController::class, 'check'])
    ->middleware('throttle:20,1')
    ->name('coupon.check');

Route::get('/sitemap.xml', [SitemapController::class, 'index'])->name('sitemap');

Route::get('/invoice/{invoiceId}', [InvoiceController::class, 'show'])
    ->where('invoiceId', '[A-Z0-9\-]+')
    ->name('invoice.show');

Route::post('/invoice/{invoiceId}/check-status', [InvoiceController::class, 'checkStatus'])
    ->where('invoiceId', '[A-Z0-9\-]+')
    ->middleware('throttle:10,1')
    ->name('invoice.check-status');

Route::get('/cek-transaksi', [TrackController::class, 'index'])->name('track.index');
Route::post('/cek-transaksi', [TrackController::class, 'search'])->middleware('throttle:20,1')->name('track.search');

Route::view('/tentang-kami', 'frontend.pages.about')->name('pages.about');
Route::view('/faq', 'frontend.pages.faq')->name('pages.faq');
Route::view('/syarat-ketentuan', 'frontend.pages.terms')->name('pages.terms');
Route::view('/kebijakan-privasi', 'frontend.pages.privacy')->name('pages.privacy');

RateLimiter::for('orders', function ($request) {
    // Batasi spam order: 10 order/menit per IP.
    return Limit::perMinute(10)->by($request->ip());
});
