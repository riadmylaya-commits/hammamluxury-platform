<?php

use App\Http\Middleware\SetLocale;
use App\Livewire\Site\BookingFlow;
use App\Livewire\Site\BookingShow;
use App\Livewire\Site\Home;
use App\Livewire\Site\Search;
use App\Livewire\Site\SpaShow;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/', function (Request $request) {
    $locale = $request->getPreferredLanguage(config('hl.locales')) ?: config('app.locale');

    return redirect("/$locale");
});

Route::prefix('{locale}')->where(['locale' => implode('|', config('hl.locales'))])->middleware(SetLocale::class)->group(function () {
    Route::get('/', Home::class)->name('home');
    Route::get('/recherche', Search::class)->name('search');
    Route::get('/spa/{spa:slug}', SpaShow::class)->name('spa.show');
    Route::get('/spa/{spa:slug}/reserver', BookingFlow::class)->name('spa.book');
    Route::get('/reservation/{token}', BookingShow::class)->name('booking.show');
});
