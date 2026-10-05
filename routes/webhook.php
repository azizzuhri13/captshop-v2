<?php

use App\Http\Controllers\Webhook\TripayController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Webhook Routes (prefix: /webhook)
|--------------------------------------------------------------------------
|
| Endpoint callback dari payment gateway. Dikelola terpisah dari web routes
| karena tanpa CSRF (signature verification menjadi penggantinya).
|
*/

Route::prefix('webhook')->group(function () {
    Route::post('/tripay', [TripayController::class, 'handle'])->middleware('throttle:60,1');
});
