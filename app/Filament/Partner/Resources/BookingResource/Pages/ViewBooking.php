<?php

namespace App\Filament\Partner\Resources\BookingResource\Pages;

use App\Domain\Phone\PhoneNumber;
use App\Filament\Partner\Resources\BookingResource;
use App\Filament\Shared\BookingActions;
use App\Models\Booking;
use Filament\Actions\Action;
use Filament\Resources\Pages\ViewRecord;

/** Fiche réservation partenaire : une seule page, lisible sur téléphone, avec tout ce qu'il faut préparer. */
class ViewBooking extends ViewRecord
{
    protected static string $resource = BookingResource::class;

    protected static string $view = 'filament.partner.pages.view-booking';

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

    public function deleteNoteAction(): Action
    {
        return Action::make('deleteNote')->label(__('partner.delete_note'))->link()->color('danger')->size('xs')
            ->requiresConfirmation()
            ->action(function (array $arguments) {
                $this->record->notes()->whereKey($arguments['note'])->delete();
            });
    }

    /** @return array<string, mixed> données prêtes à afficher */
    public function sheet(): array
    {
        /** @var Booking $b */
        $b = $this->record->loadMissing(['spa', 'participants', 'notes.user']);
        $q = $b->quote ?? [];
        $iso = PhoneNumber::countryOf($b->phone);

        return [
            'phone' => PhoneNumber::format($b->phone),
            'tel' => PhoneNumber::isValid($b->phone) ? 'tel:'.$b->phone : null,
            'whatsapp' => PhoneNumber::whatsappUrl($b->phone),
            'dial' => $iso ? PhoneNumber::countryName($iso).' (+'.PhoneNumber::dialCode($iso).')' : null,
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
