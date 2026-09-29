<?php

namespace App\Livewire\Site;

use App\Domain\Booking\BookingException;
use App\Domain\Booking\BookingService;
use App\Domain\Booking\CancellationService;
use App\Domain\Messaging\MessageService;
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

    public string $messageBody = '';

    public function mount(string $token): void
    {
        $this->booking = Booking::where('manage_token', $token)->with(['spa.photos', 'participants'])->firstOrFail();
        app(MessageService::class)->markRead($this->booking, 'client');
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

    /** Réponse du client à la demande d'annulation du partenaire (accord ou refus). */
    public function respondCancellation(string $response, CancellationService $cancellations): void
    {
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
        $this->booking->loadMissing(['messages', 'cancellationRequests']);

        return view('livewire.site.booking-show')->title(__('ui.booking_ref', ['ref' => $this->booking->reference]));
    }
}
