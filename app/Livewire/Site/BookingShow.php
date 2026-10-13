<?php

namespace App\Livewire\Site;

use App\Domain\Booking\BookingException;
use App\Domain\Booking\BookingService;
use App\Domain\Booking\CancellationService;
use App\Domain\Messaging\MessageService;
use App\Models\Booking;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\RateLimiter;
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

    public string $messageBody = '';

    public function mount(string $token): void
    {
        $this->booking = Booking::where('manage_token', $token)->with(['spa.photos', 'participants'])->firstOrFail();
        abort_unless($this->booking->manageLinkIsValid(), 410);
        app(MessageService::class)->markRead($this->booking, 'client');
    }

    /** Limite de débit par lien + IP : une action répétée au-delà du quota est refusée sans toucher à la réservation. */
    private function throttled(string $action, int $max, int $seconds): bool
    {
        $key = 'booking-show:'.$action.':'.$this->booking->id.':'.request()->ip();
        if (RateLimiter::tooManyAttempts($key, $max)) {
            $this->error = __('ui.too_many_attempts');

            return true;
        }
        RateLimiter::hit($key, $seconds);

        return false;
    }

    public function cancel(BookingService $bookings): void
    {
        if ($this->throttled('cancel', 5, 3600)) {
            return;
        }
        try {
            $bookings->cancel($this->booking, 'client');
            $this->booking->refresh();
            $this->flash = __('ui.cancelled_ok');
        } catch (BookingException $e) {
            $this->error = $e->getMessage();
        }
    }

    /** Réponse du client à la demande d'annulation du partenaire (accord ou refus). */
    public function respondCancellation(string $response, CancellationService $cancellations): void
    {
        if ($this->throttled('respond', 10, 3600)) {
            return;
        }
        $request = $this->booking->pendingCancellationRequest()->first();
        if (! $request) {
            $this->error = __('ui.cancel_request_none');

            return;
        }
        try {
            $cancellations->clientRespond($request, $response);
            $this->booking->refresh();
            $this->flash = __('ui.cancel_request_answered');
        } catch (BookingException $e) {
            $this->error = $e->getMessage();
        }
    }

    /** Réponse du client à la nouvelle date proposée par HammamLuxury : accepter déplace la réservation, refuser la laisse confirmée. */
    public function respondProposal(string $response, CancellationService $cancellations): void
    {
        if ($this->throttled('respond', 10, 3600)) {
            return;
        }
        $request = $this->booking->pendingCancellationRequest()->first();
        if (! $request || ! $request->hasOpenProposal()) {
            $this->error = __('ui.cancel_request_none');

            return;
        }
        try {
            $cancellations->clientRespondProposal($request, $response);
            $this->booking->refresh()->unsetRelation('cancellationRequests');
            $this->flash = $response === 'accepted' ? __('ui.proposal_accepted_ok') : __('ui.cancel_request_answered');
        } catch (BookingException $e) {
            $this->error = $e->getMessage();
        }
    }

    public function sendMessage(MessageService $messages): void
    {
        if ($this->throttled('message', 20, 3600)) {
            return;
        }
        try {
            $messages->send($this->booking, 'client', $this->messageBody);
            $this->messageBody = '';
            $this->booking->unsetRelation('messages');
            $this->flash = __('ui.message_sent');
        } catch (BookingException $e) {
            $this->error = $e->getMessage();
        }
    }

    public function render(): View
    {
        $this->booking->loadMissing(['messages', 'cancellationRequests', 'review']);

        return view('livewire.site.booking-show')->title(__('ui.booking_ref', ['ref' => $this->booking->reference]));
    }
}
