<?php

namespace App\Filament\Shared;

use App\Domain\Booking\BookingException;
use App\Domain\Booking\BookingService;
use App\Domain\Booking\CancellationService;
use App\Domain\Booking\IncidentService;
use App\Domain\Phone\PhoneNumber;
use App\Models\Booking;
use App\Models\BookingParticipant;
use App\Models\CancellationRequest;
use App\Models\ClientIncident;
use Filament\Actions\Action as PageAction;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Get;
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
            'declined', 'cancelled', 'expired', 'no_show', 'partner_no_show' => 'danger',
            default => 'gray',
        };
    }

    /** Justificatif facultatif (photo/PDF), stocké sur le disque privé, jamais servi publiquement. */
    public static function evidenceUpload(string $label): FileUpload
    {
        return FileUpload::make('evidence')->label($label)->disk('local')->directory('evidence')->visibility('private')
            ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp', 'application/pdf'])->maxSize(8192)
            ->helperText(__('partner.evidence_help'));
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
                ->visible(fn (Booking $b) => $actor === 'admin'
                    ? in_array($b->status, ['confirmed', 'completed'], true) && $b->start_at->isPast()
                    : $b->partnerNoShowWindowOpen())
                ->modalHeading(__('partner.no_show'))
                ->modalDescription(fn (Booking $b) => __('partner.no_show_help', ['until' => $b->end_at->addHours(Booking::NO_SHOW_WINDOW_HOURS)->format('d/m H:i')]))
                ->form(fn (Booking $b) => [
                    Radio::make('fee')->label(__('partner.no_show_fee_question'))->required()->options([
                        'apply' => __('partner.no_show_fee_apply', ['amount' => number_format((float) $b->total, 0, ',', ' ').' '.config('hl.currency')]),
                        'waive' => __('partner.no_show_fee_waive'),
                    ])->descriptions([
                        'apply' => __('partner.no_show_fee_apply_help', ['commission' => number_format((float) $b->commission_amount, 0, ',', ' ').' '.config('hl.currency')]),
                        'waive' => __('partner.no_show_fee_waive_help'),
                    ])->default('apply'),
                    Textarea::make('note')->label(__('partner.no_show_note'))->rows(2)->maxLength(500),
                ])
                ->modalSubmitActionLabel(__('partner.no_show_confirm'))
                ->action(fn (Booking $b, array $data) => $run($b, 'noShow', [$data['fee'] !== 'waive', $data['note'] ?? null])),
            $class::make('partner_no_show')->label(__('partner.partner_no_show'))->icon('heroicon-o-building-storefront')->color('danger')
                ->visible(fn (Booking $b) => $actor === 'admin' && in_array($b->status, ['confirmed', 'completed'], true) && $b->start_at->isPast())
                ->requiresConfirmation()->modalDescription(__('partner.partner_no_show_help'))
                ->form([Textarea::make('note')->label(__('partner.no_show_note'))->rows(2)->maxLength(500)])
                ->action(fn (Booking $b, array $data) => $run($b, 'partnerNoShow', [$data['note'] ?? null])),
            $class::make('reportGuest')->label(__('partner.report_guest'))->icon('heroicon-o-flag')->color('warning')
                ->visible(fn (Booking $b) => $actor === 'partner' && $b->canReportGuest())
                ->modalHeading(__('partner.report_guest'))->modalDescription(__('partner.report_guest_help'))
                ->form([
                    Select::make('category')->label(__('partner.report_category'))->required()->native(false)
                        ->options(collect(ClientIncident::CATEGORIES)->mapWithKeys(fn ($c) => [$c => __('partner.report_categories.'.$c)])->all()),
                    Textarea::make('description')->label(__('partner.report_description'))->rows(4)->required()->minLength(20)->maxLength(2000)
                        ->placeholder(__('partner.report_description_placeholder')),
                    self::evidenceUpload(__('partner.report_evidence')),
                ])
                ->modalSubmitActionLabel(__('partner.send_report'))
                ->action(function (Booking $b, array $data) {
                    try {
                        app(IncidentService::class)->reportGuest($b, auth()->user(), $data['category'], $data['description'], $data['evidence'] ?? null);
                        Notification::make()->title(__('partner.report_sent'))->body(__('partner.report_sent_body'))->success()->send();
                    } catch (BookingException $e) {
                        Notification::make()->title(__('partner.action_failed'))->body($e->getMessage())->danger()->send();
                    }
                }),
            $class::make('cancel')->label(__('partner.cancel'))->icon('heroicon-o-trash')->color('danger')
                ->visible(fn (Booking $b) => $actor === 'admin' && $b->isConfirmed())
                ->requiresConfirmation()->modalDescription(__('partner.cancel_help'))
                ->action(fn (Booking $b) => $run($b, 'cancel')),
            $class::make('requestCancellation')->label(__('partner.request_cancellation'))->icon('heroicon-o-hand-raised')->color('danger')
                ->visible(fn (Booking $b) => $actor === 'partner' && $b->canRequestCancellation())
                ->modalHeading(__('partner.request_cancellation'))->modalDescription(__('partner.request_cancellation_help'))
                ->form([
                    Select::make('reason_code')->label(__('partner.cancellation_reason_code'))->required()->native(false)->live()
                        ->options(collect(CancellationRequest::REASON_CODES)->mapWithKeys(fn ($c) => [$c => __('partner.cancellation_reason_codes.'.$c)])->all()),
                    Textarea::make('reason')->label(__('partner.cancellation_reason'))->rows(4)->required()
                        ->minLength(fn (Get $get) => $get('reason_code') === 'other' ? 30 : 20)->maxLength(1000)
                        ->placeholder(__('partner.cancellation_reason_placeholder'))
                        ->helperText(fn (Get $get) => $get('reason_code') === 'other' ? __('partner.cancellation_other_help') : null),
                    self::evidenceUpload(__('partner.cancellation_evidence')),
                ])
                ->modalSubmitActionLabel(__('partner.send_request'))
                ->action(function (Booking $b, array $data) {
                    try {
                        app(CancellationService::class)->request($b, auth()->user(), $data['reason'], $data['reason_code'], $data['evidence'] ?? null);
                        Notification::make()->title(__('partner.cancellation_requested'))->body(__('partner.cancellation_requested_body'))->success()->send();
                    } catch (BookingException $e) {
                        Notification::make()->title(__('partner.action_failed'))->body($e->getMessage())->danger()->send();
                    }
                }),
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

    public static function requestColor(string $status): string
    {
        return match ($status) {
            'pending' => 'warning',
            'accepted' => 'danger',
            'refused', 'rescheduled' => 'success',
            default => 'gray',
        };
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
                TextEntry::make('no_show_fee')->label(__('partner.no_show_fee'))->badge()->visible(fn (Booking $b) => $b->status === 'no_show')
                    ->getStateUsing(fn (Booking $b) => $b->no_show_fee_waived ? __('partner.no_show_fee_waived') : $money($b->no_show_fee))
                    ->color(fn (Booking $b) => $b->no_show_fee_waived ? 'gray' : 'warning'),
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
                TextEntry::make('email')->label('E-mail')->copyable()->visible($withCommission),
                TextEntry::make('phone')->label(__('partner.phone'))->copyable(fn (Booking $b) => $withCommission || $b->contactVisibleToPartner())
                    ->formatStateUsing(fn (?string $state, Booking $b) => $withCommission || $b->contactVisibleToPartner() ? PhoneNumber::format($state) : __('partner.contact_hidden_until_confirmed')),
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

            Section::make(__('partner.cancellation_requests'))->collapsed()->visible(fn (Booking $b) => $b->cancellationRequests()->exists())->schema([
                RepeatableEntry::make('cancellationRequests')->label('')->schema([
                    TextEntry::make('created_at')->label(__('partner.requested_at'))->dateTime('d/m/Y H:i'),
                    TextEntry::make('status')->label(__('partner.status'))->badge()
                        ->formatStateUsing(fn ($state) => __('partner.cancellation_statuses')[$state] ?? $state)->color(fn ($state) => self::requestColor($state)),
                    TextEntry::make('client_response')->label(__('partner.client_response'))->placeholder(__('partner.no_response_yet'))
                        ->formatStateUsing(fn ($state) => __('partner.client_responses')[$state] ?? $state),
                    TextEntry::make('decided_at')->label(__('partner.decided_at'))->dateTime('d/m/Y H:i')->placeholder('—'),
                    TextEntry::make('reason_code')->label(__('partner.cancellation_reason_code'))->placeholder('—')
                        ->formatStateUsing(fn ($state) => $state ? __('partner.cancellation_reason_codes.'.$state) : null),
                    TextEntry::make('proposed_start_at')->label(__('partner.proposed_start_at'))->dateTime('d/m/Y H:i')->placeholder('—')
                        ->helperText(fn (CancellationRequest $r) => $r->proposal_response ? __('partner.proposal_responses')[$r->proposal_response] ?? $r->proposal_response : null),
                    TextEntry::make('reason')->label(__('partner.cancellation_reason'))->columnSpanFull(),
                    TextEntry::make('decision_note')->label(__('partner.decision_note'))->placeholder('—')->columnSpanFull()->visible($withCommission),
                ])->columns(4),
            ]),

            Section::make(__('partner.messages'))->description(__('partner.messages_admin_help'))->collapsed()->visible($withCommission)->schema([
                RepeatableEntry::make('messages')->label('')->schema([
                    TextEntry::make('created_at')->label('')->dateTime('d/m/Y H:i'),
                    TextEntry::make('sender')->label('')->badge()->formatStateUsing(fn ($state) => __('partner.senders')[$state] ?? $state)
                        ->color(fn ($state) => $state === 'client' ? 'info' : 'primary'),
                    TextEntry::make('body')->label('')->columnSpan(2),
                ])->columns(4)->placeholder(__('partner.no_messages')),
            ]),

            Section::make(__('partner.guest_reports'))->collapsed()->visible(fn (Booking $b) => $b->incidents()->exists())->schema([
                RepeatableEntry::make('incidents')->label('')->schema([
                    TextEntry::make('created_at')->label('')->dateTime('d/m/Y H:i'),
                    TextEntry::make('category')->label('')->badge()->color('warning')->formatStateUsing(fn ($state) => __('partner.report_categories.'.$state)),
                    TextEntry::make('status')->label('')->badge()->formatStateUsing(fn ($state) => __('admin.incident_statuses')[$state] ?? $state)->visible($withCommission),
                    TextEntry::make('description')->label('')->columnSpanFull(),
                ])->columns(3),
            ]),

            Section::make(__('partner.history'))->collapsed()->schema([
                RepeatableEntry::make('events')->label('')->schema([
                    TextEntry::make('created_at')->label('')->dateTime('d/m/Y H:i'),
                    TextEntry::make('type')->label('')->formatStateUsing(fn ($state) => __('partner.events')[$state] ?? $state),
                    TextEntry::make('actor')->label('')->placeholder('—'),
                ])->columns(3),
            ]),
        ];
    }
}
