<?php

use App\Http\Controllers\Frontend\CouponController;
use App\Http\Controllers\Frontend\GoogleAuthController;
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

Route::post('/order', [OrderController::class, 'store'])
    ->middleware('throttle:orders')
    ->name('order.store');

Route::post('/coupon/check', [CouponController::class, 'check'])
    ->middleware('throttle:5,1')
    ->name('coupon.check');

Route::post('/game/check-nickname', [\App\Http\Controllers\Frontend\GameValidatorController::class, 'check'])
    ->middleware('throttle:20,1')
    ->name('game.check-nickname');

Route::get('/sitemap.xml', [SitemapController::class, 'index'])->name('sitemap');

Route::get('/invoice/{invoiceId}', [InvoiceController::class, 'show'])
    ->where('invoiceId', '[A-Z0-9\-]+')
    ->middleware('throttle:60,1')
    ->name('invoice.show');

Route::post('/invoice/{invoiceId}/check-status', [InvoiceController::class, 'checkStatus'])
    ->where('invoiceId', '[A-Z0-9\-]+')
    ->middleware('throttle:10,1')
    ->name('invoice.check-status');

Route::get('/cek-transaksi', [TrackController::class, 'index'])->name('track.index');
Route::post('/cek-transaksi', [TrackController::class, 'search'])->middleware('throttle:20,1')->name('track.search');

// Otentikasi Pengguna (Google OAuth)
Route::get('/login', [GoogleAuthController::class, 'loginView'])->name('login');
Route::get('/auth/google', [GoogleAuthController::class, 'redirect'])->middleware('throttle:10,1')->name('auth.google');
Route::get('/auth/google/callback', [GoogleAuthController::class, 'callback'])->name('auth.google.callback');
Route::post('/logout', [GoogleAuthController::class, 'logout'])->name('logout');

Route::view('/tentang-kami', 'frontend.pages.about')->name('pages.about');
Route::view('/faq', 'frontend.pages.faq')->name('pages.faq');
Route::view('/syarat-ketentuan', 'frontend.pages.terms')->name('pages.terms');
Route::view('/kebijakan-privasi', 'frontend.pages.privacy')->name('pages.privacy');
Route::view('/kalkulator-wr', 'frontend.tools.calculator')->name('tools.calculator');
Route::redirect('/calculator-wr', '/kalkulator-wr', 301);

// Livestreaming OBS Overlay
Route::view('/overlay', 'frontend.overlay.stream')->name('overlay.stream');
Route::view('/overlay/stream', 'frontend.overlay.stream');
Route::view('/overlay/dock', 'frontend.overlay.dock')->name('overlay.dock');
Route::view('/overlay/tiktok', 'frontend.overlay.dock');
Route::view('/overlay/vertical', 'frontend.overlay.vertical')->name('overlay.vertical');
Route::view('/overlay/portrait', 'frontend.overlay.vertical');
Route::view('/overlay/corner', 'frontend.overlay.corner')->name('overlay.corner');
Route::view('/overlay/simple', 'frontend.overlay.corner');
Route::view('/overlay/banner', 'frontend.overlay.banner')->name('overlay.banner');

// Modul Artikel & Blog Gaming
Route::get('/artikel', [\App\Http\Controllers\Frontend\ArticleController::class, 'index'])->name('articles.index');
Route::get('/artikel/{slug}', [\App\Http\Controllers\Frontend\ArticleController::class, 'show'])
    ->where('slug', '[a-z0-9-]+')
    ->name('articles.show');

// Alias bahasa Inggris (untuk integrasi Google Cloud Console & OAuth Verification)
Route::redirect('/privacy-policy', '/kebijakan-privasi', 301);
Route::redirect('/privacy', '/kebijakan-privasi', 301);
Route::redirect('/terms-of-service', '/syarat-ketentuan', 301);
Route::redirect('/terms', '/syarat-ketentuan', 301);

// Shortcut rute /topup/{slug}
Route::get('/topup/{slug}', [ProductController::class, 'showTopup'])
    ->where('slug', '[a-z0-9-]+')
    ->name('product.topup');

// Catch-all route produk ditempatkan di paling bawah agar tidak membajak route static
Route::get('/{category}/{slug}', [ProductController::class, 'show'])
    ->where('category', '[a-z0-9-]+')
    ->where('slug', '[a-z0-9-]+')
    ->name('product.show');

RateLimiter::for('orders', function ($request) {
    // Batasi spam order: 10 order/menit per IP.
    return Limit::perMinute(10)->by($request->ip());
});
