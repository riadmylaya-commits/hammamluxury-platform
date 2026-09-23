<?php

namespace App\Livewire\Site;

use App\Domain\Booking\BookingException;
use App\Domain\Booking\BookingService;
use App\Domain\Booking\CapacityEngine;
use App\Domain\Booking\QuoteBuilder;
use App\Domain\Catalogue\CatalogueService;
use App\Models\Spa;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Parcours de réservation invité en 4 étapes : soin(s) + personnes + extras → date & heure → coordonnées → confirmation.
 * Aucune logique de prix ou de disponibilité ici : tout passe par QuoteBuilder / CapacityEngine / BookingService.
 */
#[Layout('components.layouts.site')]
class BookingFlow extends Component
{
    public Spa $spa;

    public int $step = 1;

    #[Url(as: 'soin')]
    public ?int $treatment = null;

    public int $party = 1;

    public bool $advanced = false;

    /** @var array<int, array{treatment:int|null, extras:array<int,int>}> mode avancé : un soin par personne */
    public array $participants = [];

    /** @var array<int,int> mode simple : extra id ⇒ quantité */
    public array $extras = [];

    public string $date = '';

    public string $time = '';

    public string $month = '';

    /** @var string[] */
    public array $slots = [];

    public string $slotsReason = '';

    public string $intentToken = '';

    public string $endAt = '';

    public string $first_name = '';

    public string $last_name = '';

    public string $email = '';

    public string $phone = '';

    public string $hotel = '';

    public string $note = '';

    public bool $terms = false;

    public string $error = '';

    public function mount(Spa $spa, CatalogueService $catalogue): void
    {
        abort_unless($catalogue->bookableSpas()->whereKey($spa->id)->exists(), 404);
        $this->spa = $spa->load('hours');
        $this->month = CarbonImmutable::now()->format('Y-m');
        if ($this->treatment && ! $this->catalogue()->firstWhere('id', $this->treatment)) {
            $this->treatment = null;
        }
    }

    // ---------- Étape 1 : soin, personnes, extras ----------

    public function selectTreatment(int $id): void
    {
        $this->treatment = $id;
        $this->extras = [];
        $this->clampParty();
    }

    public function changeParty(int $delta): void
    {
        $this->party = max(1, min(config('hl.max_participants'), $this->party + $delta));
        $this->clampParty();
        $this->syncParticipants();
    }

    public function toggleAdvanced(): void
    {
        $this->advanced = ! $this->advanced;
        if ($this->advanced) {
            $this->party = max(2, $this->party);
            $this->participants = [];
            for ($i = 0; $i < $this->party; $i++) {
                $this->participants[$i] = ['treatment' => $this->treatment, 'extras' => $this->extras];
            }
        } else {
            $this->treatment = $this->participants[0]['treatment'] ?? $this->treatment;
            $this->extras = [];
        }
    }

    public function setParticipantTreatment(int $i, int $id): void
    {
        if (isset($this->participants[$i])) {
            $this->participants[$i] = ['treatment' => $id, 'extras' => []];
        }
    }

    public function toggleExtra(int $extraId, ?int $participant = null): void
    {
        $t = $participant === null ? $this->treatment : ($this->participants[$participant]['treatment'] ?? null);
        $def = collect($this->catalogue()->firstWhere('id', $t)['extras'] ?? [])->firstWhere('id', $extraId);
        if (! $def) {
            return;
        }
        $bag = $participant === null ? $this->extras : $this->participants[$participant]['extras'];
        $qty = ($bag[$extraId] ?? 0) + 1;
        if ($qty > $def['max_qty']) {
            unset($bag[$extraId]);
        } else {
            $bag[$extraId] = $qty;
        }
        if ($participant === null) {
            $this->extras = $bag;
        } else {
            $this->participants[$participant]['extras'] = $bag;
        }
    }

    // ---------- Étape 2 : date & heure ----------

    public function shiftMonth(int $delta): void
    {
        $m = CarbonImmutable::parse($this->month.'-01')->addMonths($delta);
        $min = CarbonImmutable::now()->startOfMonth();
        $max = $min->addMonths(6);
        $this->month = max($min, min($max, $m))->format('Y-m');
    }

    public function pickDate(string $date): void
    {
        $this->date = $date;
        $this->time = '';
        $this->intentToken = '';
        $this->loadSlots();
    }

    public function pickTime(string $time): void
    {
        $this->time = $time;
        $this->error = '';
    }

    public function loadSlots(): void
    {
        $quote = $this->quote();
        if (! $this->date || ! $quote['ok']) {
            $this->slots = [];
            $this->slotsReason = implode(' ', $quote['errors'] ?? []);

            return;
        }
        $av = app(CapacityEngine::class)->availability($this->spa, $this->date, $quote['items'], null, CarbonImmutable::now()->addMinutes($this->spa->minLead()));
        $this->slots = $av['times'];
        $this->slotsReason = $av['reason'];
    }

    // ---------- Navigation ----------

    public function next(): void
    {
        $this->error = '';
        if ($this->step === 1) {
            $q = $this->quote();
            if (! $q['ok']) {
                $this->error = implode(' ', $q['errors']);

                return;
            }
            $this->step = 2;
            if ($this->date) {
                $this->loadSlots();
            }
        } elseif ($this->step === 2) {
            if (! $this->date || ! $this->time) {
                return;
            }
            try {
                $p = app(BookingService::class)->prepare($this->spa, $this->request());
                $this->intentToken = $p['intent']['token'];
                $this->endAt = $p['end_at'];
                $this->step = 3;
            } catch (BookingException $e) {
                $this->error = $e->reason === 'unavailable' ? __('ui.slot_gone') : $e->getMessage();
                $this->time = '';
                $this->loadSlots();
            }
        }
    }

    public function back(): void
    {
        $this->error = '';
        $this->step = max(1, $this->step - 1);
    }

    public function submit(): void
    {
        $this->validate([
            'first_name' => 'required|string|max:90',
            'last_name' => 'required|string|max:90',
            'email' => 'required|email|max:190',
            'phone' => 'required|string|min:6|max:40',
            'hotel' => 'nullable|string|max:190',
            'note' => 'nullable|string|max:1000',
            'terms' => 'accepted',
        ]);
        $customer = [
            'first_name' => $this->first_name, 'last_name' => $this->last_name, 'email' => $this->email,
            'phone' => $this->phone, 'hotel' => $this->hotel ?: null, 'note' => $this->note ?: null,
        ];
        try {
            $booking = app(BookingService::class)->confirmIntent($this->spa, $this->intentToken, $customer);
        } catch (BookingException $e) {
            if (in_array($e->reason, ['unavailable', 'intent_expired', 'intent_replayed', 'price_changed', 'busy'], true)) {
                $this->error = $e->reason === 'unavailable' ? __('ui.slot_gone') : $e->getMessage();
                $this->step = 2;
                $this->time = '';
                $this->intentToken = '';
                $this->loadSlots();

                return;
            }
            $this->error = $e->getMessage();

            return;
        }
        $this->redirectRoute('booking.show', ['token' => $booking->manage_token, 'new' => 1]);
    }

    // ---------- Helpers ----------

    /** Requête au format QuoteBuilder::fromRequest / BookingService::prepare */
    public function request(): array
    {
        $req = ['date' => $this->date, 'time' => $this->time];
        if ($this->advanced) {
            $req['participants'] = array_values(array_map(fn ($p) => ['treatment' => $p['treatment'], 'extras' => $p['extras']], $this->participants));
        } else {
            $req += ['treatment' => $this->treatment, 'party' => $this->party, 'extras' => $this->extras];
        }

        return $req;
    }

    public function quote(): array
    {
        if (! $this->treatment && ! $this->advanced) {
            return ['ok' => false, 'errors' => [], 'total' => 0, 'duration_min' => 0, 'lines' => [], 'party' => $this->party];
        }
        try {
            return app(QuoteBuilder::class)->fromRequest($this->spa, $this->request());
        } catch (BookingException $e) {
            return ['ok' => false, 'errors' => [$e->getMessage()], 'total' => 0, 'duration_min' => 0, 'lines' => [], 'party' => $this->party];
        }
    }

    public function catalogue(): \Illuminate\Support\Collection
    {
        return once(fn () => app(CatalogueService::class)->treatments($this->spa));
    }

    /** @return array<int, array{date:string, day:int, open:bool, past:bool}> */
    public function calendar(): array
    {
        $first = CarbonImmutable::parse($this->month.'-01');
        $today = CarbonImmutable::now()->startOfDay();
        $openDays = $this->spa->hours->pluck('weekday')->unique()->all();
        $days = [];
        for ($i = 0; $i < ($first->dayOfWeekIso - 1); $i++) {
            $days[] = null;
        }
        for ($d = $first; $d->month === $first->month; $d = $d->addDay()) {
            $days[] = ['date' => $d->format('Y-m-d'), 'day' => $d->day, 'open' => in_array($d->dayOfWeekIso - 1, $openDays, true), 'past' => $d < $today];
        }

        return $days;
    }

    private function clampParty(): void
    {
        $t = $this->catalogue()->firstWhere('id', $this->treatment);
        if ($t && ! $this->advanced) {
            $this->party = max($t['party_min'], min($t['party_max'], $this->party));
        }
    }

    private function syncParticipants(): void
    {
        if (! $this->advanced) {
            return;
        }
        $this->participants = array_slice($this->participants, 0, $this->party);
        while (count($this->participants) < $this->party) {
            $this->participants[] = ['treatment' => $this->treatment, 'extras' => []];
        }
        if ($this->party < 2) {
            $this->advanced = false;
        }
    }

    public function render(): View
    {
        return view('livewire.site.booking-flow', [
            'treatments' => $this->catalogue(),
            'quote' => $this->quote(),
            'calendar' => $this->step === 2 ? $this->calendar() : [],
        ])->title(__('ui.book').' · '.$this->spa->name);
    }
}
