<?php

namespace App\Filament\Partner\Resources\BookingResource\Pages;

use App\Filament\Partner\Resources\BookingResource;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Filament\Actions\Action;
use Filament\Resources\Pages\Page;
use Illuminate\Support\Collection;
use Livewire\Attributes\Url;

/**
 * Vue Calendrier des réservations : un point par jour reflète l'activité réelle de l'établissement
 * (bleu = confirmée / à venir, gris = terminée), jamais les annulations ni les refus. Le jour sélectionné
 * liste toutes ses réservations, statuts compris.
 */
class CalendarBookings extends Page
{
    protected static string $resource = BookingResource::class;

    protected static string $view = 'filament.partner.pages.calendar-bookings';

    #[Url]
    public string $month = '';

    #[Url]
    public string $day = '';

    public function mount(): void
    {
        $today = CarbonImmutable::today();
        $this->day = $this->day ?: $today->toDateString();
        $this->month = $this->month ?: substr($this->day, 0, 7);
    }

    public function getTitle(): string
    {
        return __('partner.bookings');
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('list')->label(__('partner.list_view'))->icon('heroicon-o-list-bullet')->color('gray')
                ->url(BookingResource::getUrl('index')),
        ];
    }

    public function previousMonth(): void
    {
        $this->month = CarbonImmutable::parse($this->month.'-01')->subMonth()->format('Y-m');
    }

    public function nextMonth(): void
    {
        $this->month = CarbonImmutable::parse($this->month.'-01')->addMonth()->format('Y-m');
    }

    public function selectDay(string $day): void
    {
        $this->day = $day;
        $this->month = substr($day, 0, 7);
    }

    /** Point à afficher pour une réservation : 'blue', 'gray' ou null (annulée, refusée, expirée, non présentée). */
    public static function dotFor(string $status, CarbonInterface $endAt, ?CarbonInterface $now = null): ?string
    {
        return match ($status) {
            'confirmed', 'waiting' => $endAt->lt($now ?? now()) ? 'gray' : 'blue',
            'completed' => 'gray',
            default => null,
        };
    }

    /** Point par jour : bleu dès qu'une réservation à venir existe, sinon gris s'il y a une réservation terminée. */
    public static function dotsByDay(Collection $bookings, ?CarbonInterface $now = null): array
    {
        $dots = [];
        foreach ($bookings as $b) {
            $d = $b->start_at->toDateString();
            $dot = static::dotFor($b->status, $b->end_at, $now);
            if ($dot === 'blue' || ($dot === 'gray' && ($dots[$d] ?? null) !== 'blue')) {
                $dots[$d] = $dot;
            }
        }

        return $dots;
    }

    /** @return array{first: CarbonImmutable, weeks: array<int, array<int, CarbonImmutable>>, dots: array<string, string>} */
    public function grid(): array
    {
        $first = CarbonImmutable::parse($this->month.'-01');
        $start = $first->startOfWeek(CarbonInterface::MONDAY);
        $end = $first->endOfMonth()->endOfWeek(CarbonInterface::SUNDAY);
        $bookings = BookingResource::getEloquentQuery()
            ->whereBetween('start_at', [$start->startOfDay(), $end->endOfDay()])
            ->get(['id', 'status', 'start_at', 'end_at']);
        $weeks = [];
        for ($d = $start; $d->lte($end); $d = $d->addWeek()) {
            $weeks[] = array_map(fn ($i) => $d->addDays($i), range(0, 6));
        }

        return ['first' => $first, 'weeks' => $weeks, 'dots' => static::dotsByDay($bookings)];
    }

    /** Toutes les réservations du jour sélectionné, annulées et refusées comprises. */
    public function dayBookings(): Collection
    {
        $day = CarbonImmutable::parse($this->day);

        return BookingResource::getEloquentQuery()->with('participants')
            ->whereBetween('start_at', [$day->startOfDay(), $day->endOfDay()])
            ->orderBy('start_at')->get();
    }
}
