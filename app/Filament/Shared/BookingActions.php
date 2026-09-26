<?php

namespace App\Filament\Shared;

use App\Domain\Booking\BookingException;
use App\Domain\Booking\BookingService;
use App\Domain\Phone\PhoneNumber;
use App\Models\Booking;
use App\Models\BookingParticipant;
use Filament\Actions\Action as PageAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\Section;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Tables\Actions\Action;

/** Actions et fiche réservation communes aux espaces partenaire et administration. */
class BookingActions
{
    public static function statusColor(string $status): string
    {
        return match ($status) {
            'waiting' => 'warning',
            'confirmed' => 'success',
            'completed' => 'info',
            'declined', 'cancelled', 'expired', 'no_show' => 'danger',
            default => 'gray',
        };
    }

    /**
     * @param  class-string<Action|PageAction>  $class
     * @return array<Action|PageAction>
     */
    public static function all(string $actor = 'partner', string $class = Action::class): array
    {
        $run = function (Booking $booking, string $method, array $args = []) use ($actor): void {
            try {
                app(BookingService::class)->{$method}($booking, $actor, ...$args);
                Notification::make()->title(__('partner.action_done'))->success()->send();
            } catch (BookingException $e) {
                Notification::make()->title(__('partner.action_failed'))->body($e->getMessage())->danger()->send();
            }
        };

        return [
            $class::make('accept')->label(__('partner.accept'))->icon('heroicon-o-check')->color('success')
                ->visible(fn (Booking $b) => $b->isWaiting())
                ->requiresConfirmation()->modalDescription(__('partner.accept_help'))
                ->action(fn (Booking $b) => $run($b, 'accept')),
            $class::make('decline')->label(__('partner.decline'))->icon('heroicon-o-x-mark')->color('danger')
                ->visible(fn (Booking $b) => $b->isWaiting())
                ->form([Textarea::make('note')->label(__('partner.decline_note'))->rows(2)->maxLength(500)])
                ->requiresConfirmation()->modalDescription(__('partner.decline_help'))
                ->action(fn (Booking $b, array $data) => $run($b, 'decline', [$data['note'] ?? null])),
            $class::make('complete')->label(__('partner.complete'))->icon('heroicon-o-flag')->color('info')
                ->visible(fn (Booking $b) => $actor === 'admin' && $b->isConfirmed() && $b->end_at->isPast())
                ->action(fn (Booking $b) => $run($b, 'complete')),
            $class::make('no_show')->label(__('partner.no_show'))->icon('heroicon-o-user-minus')->color('gray')
                ->visible(fn (Booking $b) => $b->isConfirmed() && $b->start_at->isPast())
                ->requiresConfirmation()
                ->action(fn (Booking $b) => $run($b, 'noShow')),
            $class::make('cancel')->label(__('partner.cancel'))->icon('heroicon-o-trash')->color('danger')
                ->visible(fn (Booking $b) => $actor === 'admin' && $b->isConfirmed())
                ->requiresConfirmation()->modalDescription(__('partner.cancel_help'))
                ->action(fn (Booking $b) => $run($b, 'cancel')),
            $class::make('payment')->label(__('partner.payment'))->icon('heroicon-o-banknotes')->color('gray')
                ->visible(fn (Booking $b) => $b->isConfirmed() || $b->status === 'completed')
                ->fillForm(fn (Booking $b) => ['payment_status' => $b->payment_status])
                ->form([Select::make('payment_status')->label(__('partner.payment_status'))->options(__('partner.payment_statuses'))->required()->native(false)])
                ->modalDescription(__('partner.payment_help'))
                ->action(fn (Booking $b, array $data) => $run($b, 'setPaymentStatus', [$data['payment_status']])),
        ];
    }

    /** Ajout d'une note interne (jamais visible du client). */
    public static function addNote(string $class = PageAction::class): PageAction|Action
    {
        return $class::make('addNote')->label(__('partner.add_note'))->icon('heroicon-o-pencil-square')->color('gray')
            ->modalHeading(__('partner.internal_notes'))->modalDescription(__('partner.internal_notes_help'))
            ->form([Textarea::make('body')->label(__('partner.note'))->rows(3)->required()->maxLength(1000)->placeholder(__('partner.note_placeholder'))])
            ->action(function (Booking $b, array $data) {
                $b->notes()->create(['spa_id' => $b->spa_id, 'user_id' => auth()->id(), 'body' => trim($data['body'])]);
                Notification::make()->title(__('partner.note_saved'))->success()->send();
            });
    }

    public static function paymentColor(string $status): string
    {
        return match ($status) {
            'paid' => 'success',
            'partial' => 'warning',
            'refunded' => 'gray',
            default => 'info',
        };
    }

    /** Schéma d'infolist d'une réservation. */
    public static function infolist(bool $withCommission = false): array
    {
        $money = fn ($state) => number_format((float) $state, fmod((float) $state, 1) ? 2 : 0, ',', ' ').' '.config('hl.currency');

        return [
            Section::make(__('partner.booking'))->schema([
                TextEntry::make('reference')->label(__('partner.reference'))->weight('bold')->copyable(),
                TextEntry::make('status')->label(__('partner.status'))->badge()
                    ->formatStateUsing(fn ($state) => __('ui.status')[$state] ?? $state)->color(fn ($state) => self::statusColor($state)),
                TextEntry::make('start_at')->label(__('partner.date_time'))->dateTime('l d F Y · H:i'),
                TextEntry::make('end_at')->label(__('partner.end'))->time('H:i'),
                TextEntry::make('party')->label(__('partner.party')),
                TextEntry::make('duration_min')->label(__('partner.duration'))->suffix(' min'),
                TextEntry::make('total')->label(__('partner.total'))->formatStateUsing($money)->weight('bold'),
                TextEntry::make('expires_at')->label(__('partner.expires_at'))->since()->visible(fn (Booking $b) => $b->isWaiting()),
                TextEntry::make('payment_status')->label(__('partner.payment_status'))->badge()
                    ->formatStateUsing(fn ($state) => __('partner.payment_statuses')[$state] ?? $state)->color(fn ($state) => self::paymentColor((string) $state)),
                TextEntry::make('commissionable_amount')->label(__('partner.commissionable'))->getStateUsing(fn (Booking $b) => $b->commissionableAmount())->formatStateUsing($money)->visible($withCommission),
                TextEntry::make('commission_amount')->label(__('partner.commission'))->formatStateUsing($money)
                    ->helperText(fn (Booking $b) => $b->commission_pct.' %')->visible($withCommission),
                TextEntry::make('net')->label(__('partner.net_partner'))->getStateUsing(fn (Booking $b) => $b->netForPartner())->formatStateUsing($money)->weight('bold')->visible($withCommission),
            ])->columns(4),

            Section::make(__('partner.participants'))->schema([
                RepeatableEntry::make('participants')->label('')->schema([
                    TextEntry::make('participant_no')->label('#')->formatStateUsing(fn ($state, $record) => $record->party > 1 ? "$state (×{$record->party})" : $state),
                    TextEntry::make('treatment_name')->label(__('partner.treatment')),
                    TextEntry::make('extras_label')->label(__('partner.extras'))
                        ->getStateUsing(fn (BookingParticipant $p) => collect($p->extras ?: [])->map(fn ($e) => ($e['name'] ?? '').(($e['qty'] ?? 1) > 1 ? ' ×'.$e['qty'] : ''))->implode(', ') ?: '—'),
                    TextEntry::make('duration_min')->label(__('partner.duration'))->suffix(' min'),
                    TextEntry::make('price')->label(__('partner.price'))->formatStateUsing($money),
                ])->columns(5),
            ]),

            Section::make(__('partner.customer'))->schema([
                TextEntry::make('customer')->label(__('partner.name'))->getStateUsing(fn (Booking $b) => $b->customerName()),
                TextEntry::make('email')->label('E-mail')->copyable(),
                TextEntry::make('phone')->label(__('partner.phone'))->formatStateUsing(fn (?string $state) => PhoneNumber::format($state))->copyable(),
                TextEntry::make('hotel')->label(__('partner.hotel'))->placeholder('—'),
                TextEntry::make('note')->label(__('partner.customer_note'))->placeholder('—')->columnSpanFull(),
                TextEntry::make('partner_note')->label(__('partner.partner_note'))->placeholder('—')->columnSpanFull(),
            ])->columns(4),

            Section::make(__('partner.internal_notes'))->description(__('partner.internal_notes_help'))->collapsed()->schema([
                RepeatableEntry::make('notes')->label('')->schema([
                    TextEntry::make('created_at')->label('')->dateTime('d/m/Y H:i'),
                    TextEntry::make('user.name')->label('')->placeholder('—'),
                    TextEntry::make('body')->label('')->columnSpan(2),
                ])->columns(4),
            ]),

            Section::make(__('partner.history'))->collapsed()->schema([
                RepeatableEntry::make('events')->label('')->schema([
                    TextEntry::make('created_at')->label('')->dateTime('d/m/Y H:i'),
                    TextEntry::make('type')->label(''),
                    TextEntry::make('actor')->label('')->placeholder('—'),
                ])->columns(3),
            ]),
        ];
    }
}
