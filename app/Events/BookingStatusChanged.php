<?php

namespace App\Events;

use App\Models\Booking;
use Illuminate\Foundation\Events\Dispatchable;

class BookingStatusChanged
{
    use Dispatchable;

    public function __construct(public Booking $booking, public string $oldStatus) {}
}
