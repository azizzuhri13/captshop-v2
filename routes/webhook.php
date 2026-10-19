<?php

use App\Http\Controllers\Api\InternalCanvaController;
use App\Http\Controllers\Api\InternalDisneyController;
use App\Http\Controllers\Api\InternalNetflixController;
use App\Http\Controllers\Webhook\BelibayarController;
use App\Http\Controllers\Webhook\DigiflazzController;
use App\Http\Controllers\Webhook\TokovoucherController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Webhook & Internal API Routes
|--------------------------------------------------------------------------
|
| Endpoint callback payment gateway dan API internal untuk Bot Telegram.
| Dikelola di grup API tanpa proteksi CSRF.
|
*/

Route::prefix('webhook')->group(function () {
    Route::post('/belibayar', [BelibayarController::class, 'handle'])->middleware('throttle:60,1');
    Route::post('/tokovoucher', [TokovoucherController::class, 'handle'])->middleware('throttle:60,1');
    Route::post('/digiflazz', [DigiflazzController::class, 'handle'])->middleware('throttle:60,1');
});

// Endpoint Private untuk Bot Telegram (Sinkronisasi Stok & Pesanan Netflix)
Route::prefix('api/internal/netflix')->middleware('throttle:60,1')->group(function () {
    Route::get('/stock', [InternalNetflixController::class, 'stock']);
    Route::get('/packages', [InternalNetflixController::class, 'packages']);
    Route::post('/reservations', [InternalNetflixController::class, 'reserve']);
    Route::get('/orders/{order_id}', [InternalNetflixController::class, 'order']);
    Route::post('/claim', [InternalNetflixController::class, 'claim']);
    Route::post('/cancel-reservation', [InternalNetflixController::class, 'cancelReservation']);

    // Publik: pembeli hanya bisa membaca OTP order miliknya via token unik.
    Route::get('/otp/{token}', [InternalNetflixController::class, 'otp']);
});

// Endpoint Private untuk Bot Telegram (Sinkronisasi Stok & Pesanan Disney+)
Route::prefix('api/internal/disney')->middleware('throttle:60,1')->group(function () {
    Route::get('/stock', [InternalDisneyController::class, 'stock']);
    Route::get('/packages', [InternalDisneyController::class, 'packages']);
    Route::post('/reservations', [InternalDisneyController::class, 'reserve']);
    Route::get('/orders/{order_id}', [InternalDisneyController::class, 'order']);
    Route::post('/claim', [InternalDisneyController::class, 'claim']);
    Route::post('/cancel-reservation', [InternalDisneyController::class, 'cancelReservation']);

    // Publik: pembeli hanya bisa membaca OTP order miliknya via token unik.
    Route::get('/otp/{token}', [InternalDisneyController::class, 'otp']);
});

// Endpoint Private untuk Bot Telegram (Sinkronisasi Stok & Pesanan Canva Pro)
Route::prefix('api/internal/canva')->middleware('throttle:60,1')->group(function () {
    Route::get('/stock', [InternalCanvaController::class, 'stock']);
    Route::get('/packages', [InternalCanvaController::class, 'packages']);
    Route::post('/reservations', [InternalCanvaController::class, 'reserve']);
    Route::get('/orders/{order_id}', [InternalCanvaController::class, 'order']);
    Route::post('/claim', [InternalCanvaController::class, 'claim']);
    Route::post('/cancel-reservation', [InternalCanvaController::class, 'cancelReservation']);

    // Publik: pembeli membaca info kredensial miliknya via token unik.
    Route::get('/otp/{token}', [InternalCanvaController::class, 'otp']);
});
