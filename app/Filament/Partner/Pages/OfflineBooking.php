<?php

namespace App\Filament\Partner\Pages;

use App\Domain\Booking\BookingException;
use App\Domain\Booking\BookingService;
use App\Domain\Booking\CapacityEngine;
use App\Domain\Booking\QuoteBuilder;
use App\Domain\Phone\PhoneNumber;
use App\Filament\Partner\Resources\BookingResource;
use App\Models\Spa;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

/**
 * Saisie d'une réservation reçue hors plateforme (téléphone, WhatsApp, réception…).
 * Les heures proposées viennent du même moteur de capacité que le site ; l'enregistrement passe par le même verrou.
 */
class OfflineBooking extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-phone-arrow-down-left';

    protected static ?int $navigationSort = 11;

    protected static string $view = 'filament.partner.pages.offline-booking';

    public ?array $data = [];

    public static function getNavigationLabel(): string
    {
        return __('partner.offline_nav');
    }

    public function getTitle(): string
    {
        return __('partner.offline_title');
    }

    public function getSubheading(): ?string
    {
        return __('partner.offline_help');
    }

    public function mount(): void
    {
        $this->form->fill(['date' => now()->toDateString(), 'party' => 1, 'channel' => 'phone', 'phone_country' => 'MA']);
    }

    private function spa(): Spa
    {
        return Filament::getTenant();
    }

    public function form(Form $form): Form
    {
        return $form->statePath('data')->schema([
            Forms\Components\Section::make(__('partner.offline_slot'))->schema([
                Forms\Components\Select::make('treatment_id')->label(__('partner.treatment'))->required()->native(false)->live()
                    ->options(fn () => $this->spa()->treatments()->where('status', 'active')->orderBy('name_fr')->pluck('name_fr', 'id'))
                    ->afterStateUpdated(fn (Forms\Set $set) => $set('time', null)),
                Forms\Components\TextInput::make('party')->label(__('partner.party'))->numeric()->minValue(1)->maxValue(config('hl.max_participants'))->default(1)->required()->live(onBlur: true)
                    ->afterStateUpdated(fn (Forms\Set $set) => $set('time', null)),
                Forms\Components\DatePicker::make('date')->label(__('partner.date'))->required()->native(false)->minDate(today())->live()
                    ->afterStateUpdated(fn (Forms\Set $set) => $set('time', null)),
                Forms\Components\Select::make('time')->label(__('partner.time'))->required()->native(false)->live()
                    ->options(fn (Get $get) => $this->slots($get))
                    ->helperText(fn (Get $get) => $this->slotsHint($get)),
            ])->columns(2),
            Forms\Components\Section::make(__('partner.customer'))->schema([
                Forms\Components\TextInput::make('first_name')->label(__('partner.first_name'))->required()->maxLength(90),
                Forms\Components\TextInput::make('last_name')->label(__('partner.last_name'))->required()->maxLength(90),
                Forms\Components\Select::make('phone_country')->label(__('phone.country'))->options(PhoneNumber::options())->default('MA')->native(false)->searchable()->required(),
                Forms\Components\TextInput::make('phone')->label(__('partner.phone'))->tel()->required()->maxLength(25),
                Forms\Components\TextInput::make('email')->label(__('partner.email_optional'))->email()->maxLength(190),
                Forms\Components\Select::make('channel')->label(__('partner.channel'))->options(__('partner.channels'))->required()->native(false),
                Forms\Components\Textarea::make('note')->label(__('partner.offline_note'))->rows(2)->maxLength(1000)->columnSpanFull(),
            ])->columns(2),
        ]);
    }

    private function items(Get $get): array
    {
        return [['treatment' => (int) $get('treatment_id'), 'party' => max(1, (int) $get('party')), 'extras' => []]];
    }

    /** @return array<string, string> */
    private function slots(Get $get): array
    {
        if (! $get('treatment_id') || ! $get('date')) {
            return [];
        }
        $quote = app(QuoteBuilder::class)->fromRequest($this->spa(), ['participants' => $this->items($get)]);
        if (! $quote['ok']) {
            return [];
        }
        $times = app(CapacityEngine::class)->availability($this->spa(), $get('date'), $quote['items'], null, CarbonImmutable::now())['times'];

        return array_combine($times, $times);
    }

    private function slotsHint(Get $get): string
    {
        if (! $get('treatment_id') || ! $get('date')) {
            return __('partner.offline_pick_first');
        }

        return $this->slots($get) === [] ? __('partner.offline_no_slot') : __('partner.offline_slots_help');
    }

    public function save(): void
    {
        $d = $this->form->getState();
        $spa = $this->spa();
        $customer = [
            'first_name' => $d['first_name'], 'last_name' => $d['last_name'],
            'phone' => PhoneNumber::normalize($d['phone'], $d['phone_country']) ?? $d['phone'],
            'email' => $d['email'] ?? '', 'note' => $d['note'] ?? null,
        ];
        try {
            $start = app(BookingService::class)->parseStart($d['date'], $d['time']);
            $booking = app(BookingService::class)->bookOffline($spa, $start, [['treatment' => (int) $d['treatment_id'], 'party' => (int) $d['party'], 'extras' => []]], $customer, $d['channel'], auth()->id());
        } catch (BookingException $e) {
            Notification::make()->title(__('partner.offline_refused'))->body($e->reason === 'unavailable' ? __('partner.offline_slot_gone') : $e->getMessage())->danger()->persistent()->send();
            $this->data['time'] = null;

            return;
        }
        Notification::make()->title(__('partner.offline_saved', ['ref' => $booking->reference]))->success()->send();
        $this->redirect(BookingResource::getUrl('view', ['record' => $booking]));
    }
}
