<?php

namespace App\Providers;

use App\Events\BookingCreated;
use App\Events\BookingExpired;
use App\Events\BookingStatusChanged;
use App\Http\Middleware\SetLocale;
use App\Listeners\SendBookingNotifications;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        Date::use(CarbonImmutable::class);
        Livewire::addPersistentMiddleware([SetLocale::class]);

        Event::listen(BookingCreated::class, [SendBookingNotifications::class, 'handleCreated']);
        Event::listen(BookingStatusChanged::class, [SendBookingNotifications::class, 'handleStatus']);
        Event::listen(BookingExpired::class, [SendBookingNotifications::class, 'handleExpired']);
    }
}
