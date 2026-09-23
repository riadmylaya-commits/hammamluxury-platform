<?php

use App\Http\Controllers\Api\V1\BookingController;
use App\Http\Controllers\Api\V1\CatalogueController;
use App\Http\Middleware\SetLocale;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->middleware(['throttle:api', SetLocale::class])->group(function () {
    Route::get('spas', [CatalogueController::class, 'index']);
    Route::get('spas/{spa:slug}', [CatalogueController::class, 'show']);
    Route::post('spas/{spa:slug}/quote', [BookingController::class, 'quote']);
    Route::post('spas/{spa:slug}/availability', [BookingController::class, 'availability']);
    Route::post('spas/{spa:slug}/booking-intent', [BookingController::class, 'intent'])->middleware('throttle:20,1');
    Route::post('spas/{spa:slug}/bookings', [BookingController::class, 'store'])->middleware('throttle:10,1');
    Route::get('bookings/{token}', [BookingController::class, 'show']);
    Route::post('bookings/{token}/cancel', [BookingController::class, 'cancel']);
});
