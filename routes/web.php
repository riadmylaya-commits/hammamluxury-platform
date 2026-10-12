<?php

use App\Http\Middleware\SetLocale;
use App\Livewire\Site\BookingFlow;
use App\Livewire\Site\BookingShow;
use App\Livewire\Site\Contact;
use App\Livewire\Site\Home;
use App\Livewire\Site\ReviewForm;
use App\Livewire\Site\Search;
use App\Livewire\Site\SpaShow;
use App\Livewire\Site\StaticPage;
use App\Models\ReviewPhoto;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;

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
    Route::get('/avis/{token}', ReviewForm::class)->name('review.form');
    Route::get('/contact', Contact::class)->name('contact');
    Route::get('/{page}', StaticPage::class)->where('page', implode('|', array_keys(StaticPage::PAGES)))->name('page');
});

// Photo d'avis encore privée (avant publication) : administration, ou partenaire de l'établissement concerné.
Route::get('/review-photos/{photo}', function (ReviewPhoto $photo, Request $request) {
    $user = $request->user();
    abort_unless($user && ($user->isAdmin() || $user->canAccessTenant($photo->review->spa)), 403);
    abort_unless(Storage::disk($photo->disk)->exists($photo->path), 404);

    return Storage::disk($photo->disk)->response($photo->path);
})->middleware('web')->name('review.photo');
