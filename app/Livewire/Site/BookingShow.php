<?php

namespace App\Livewire\Site;

use App\Domain\Booking\BookingException;
use App\Domain\Booking\BookingService;
use App\Models\Booking;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

#[Layout('components.layouts.site')]
class BookingShow extends Component
{
    public Booking $booking;

    #[Url]
    public bool $new = false;

    public string $flash = '';

    public string $error = '';

    public function mount(string $token): void
    {
        $this->booking = Booking::where('manage_token', $token)->with(['spa.photos', 'participants'])->firstOrFail();
    }

    public function cancel(BookingService $bookings): void
    {
        try {
            $bookings->cancel($this->booking, 'client');
            $this->booking->refresh();
            $this->flash = __('ui.cancelled_ok');
        } catch (BookingException $e) {
            $this->error = $e->getMessage();
        }
    }

    public function render(): View
    {
        return view('livewire.site.booking-show')->title(__('ui.booking_ref', ['ref' => $this->booking->reference]));
    }
}
