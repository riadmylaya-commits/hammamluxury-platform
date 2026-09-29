<?php

namespace App\Filament\Partner\Resources\BookingResource\Pages;

use App\Domain\Booking\BookingException;
use App\Domain\Messaging\MessageService;
use App\Domain\Phone\PhoneNumber;
use App\Filament\Partner\Resources\BookingResource;
use App\Filament\Shared\BookingActions;
use App\Models\Booking;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;

/** Fiche réservation partenaire : une seule page, lisible sur téléphone, avec tout ce qu'il faut préparer. */
class ViewBooking extends ViewRecord
{
    protected static string $resource = BookingResource::class;

    protected static string $view = 'filament.partner.pages.view-booking';

    public string $messageBody = '';

    /** Ouvrir la fiche marque comme lus les messages du client. */
    public function mount(int|string $record): void
    {
        parent::mount($record);
        app(MessageService::class)->markRead($this->record, 'partner');
    }

    public function sendMessage(): void
    {
        try {
            app(MessageService::class)->send($this->record, 'partner', $this->messageBody, auth()->user());
            $this->messageBody = '';
            $this->record->unsetRelation('messages');
            Notification::make()->title(__('partner.message_sent'))->success()->send();
        } catch (BookingException $e) {
            Notification::make()->title(__('partner.action_failed'))->body($e->getMessage())->danger()->send();
        }
    }

    public function getTitle(): string
    {
        return $this->record->customerName();
    }

    public function getSubheading(): ?string
    {
        return $this->record->reference;
    }

    /** Accepter / refuser / client absent / paiement : jamais de « terminée » ni d'annulation directe côté partenaire. */
    protected function getHeaderActions(): array
    {
        return BookingActions::all('partner', Action::class);
    }

    public function addNoteAction(): Action
    {
        return BookingActions::addNote()->record($this->record);
    }

    /** @return array<string, mixed> données prêtes à afficher */
    public function sheet(): array
    {
        /** @var Booking $b */
        $b = $this->record->loadMissing(['spa', 'participants', 'notes.user', 'messages', 'cancellationRequests', 'incidents']);
        $q = $b->quote ?? [];
        $iso = PhoneNumber::countryOf($b->phone);
        $contact = $b->contactVisibleToPartner();

        return [
            'contact_visible' => $contact,
            'phone' => $contact ? PhoneNumber::format($b->phone) : null,
            'tel' => $contact && PhoneNumber::isValid($b->phone) ? 'tel:'.$b->phone : null,
            'whatsapp' => $contact ? PhoneNumber::whatsappUrl($b->phone) : null,
            'dial' => $iso ? PhoneNumber::countryName($iso).' (+'.PhoneNumber::dialCode($iso).')' : null,
            'no_show_until' => $b->end_at->addHours(Booking::NO_SHOW_WINDOW_HOURS),
            'language' => __('ui.practical.lang_'.$b->locale) !== 'ui.practical.lang_'.$b->locale ? __('ui.practical.lang_'.$b->locale) : strtoupper($b->locale),
            'lines' => $q['lines'] ?? [],
            'commissionable' => $b->commissionableAmount(),
            'net' => $b->netForPartner(),
            'duration' => self::humanDuration($b->duration_min),
        ];
    }

    public static function humanDuration(int $min): string
    {
        $h = intdiv($min, 60);
        $m = $min % 60;

        return $h ? ($m ? sprintf('%dh%02d', $h, $m) : $h.'h') : $m.' min';
    }

    public static function money(float $v): string
    {
        return number_format($v, fmod($v, 1) ? 2 : 0, ',', ' ').' '.config('hl.currency');
    }
}
