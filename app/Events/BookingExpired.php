<?php

namespace App\Events;

use App\Models\Booking;
use Illuminate\Foundation\Events\Dispatchable;

class BookingExpired
{
    use Dispatchable;

    public function __construct(public Booking $booking) {}
}
