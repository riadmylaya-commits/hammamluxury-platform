<?php

namespace App\Providers;

use App\Events\BookingCreated;
use App\Events\BookingExpired;
use App\Events\BookingStatusChanged;
use App\Http\Middleware\SetLocale;
use App\Listeners\SendBookingNotifications;
use Carbon\CarbonImmutable;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
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
        RateLimiter::for('api', fn (Request $r) => Limit::perMinute(120)->by($r->ip()));

        Event::listen(BookingCreated::class, [SendBookingNotifications::class, 'handleCreated']);
        Event::listen(BookingStatusChanged::class, [SendBookingNotifications::class, 'handleStatus']);
        Event::listen(BookingExpired::class, [SendBookingNotifications::class, 'handleExpired']);
    }
}
