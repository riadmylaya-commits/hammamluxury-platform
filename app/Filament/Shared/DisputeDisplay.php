<?php

namespace App\Filament\Shared;

use App\Models\CommissionDispute;
use Filament\Infolists\Components\Section;
use Filament\Infolists\Components\TextEntry;
use Filament\Tables;
use Illuminate\Support\Facades\Storage;

/** Colonnes, fiche et téléchargement du justificatif communs aux espaces Partenaire et Admin. */
class DisputeDisplay
{
    public static function statusColor(string $status): string
    {
        return match ($status) {
            'open' => 'warning',
            'accepted' => 'success',
            'rejected' => 'gray',
            default => 'gray',
        };
    }

    /** @return array<int, Tables\Columns\Column> */
    public static function columns(bool $withPartner = false): array
    {
        return array_values(array_filter([
            Tables\Columns\TextColumn::make('created_at')->label(__('billing.opened_at'))->dateTime('d/m/Y H:i')->sortable(),
            $withPartner ? Tables\Columns\TextColumn::make('partner.company_name')->label(__('billing.partner'))->searchable()->sortable() : null,
            Tables\Columns\TextColumn::make('spa.name')->label(__('billing.spa'))->sortable(),
            Tables\Columns\TextColumn::make('booking.reference')->label(__('booking.reference'))->searchable()->fontFamily('mono')
                ->icon(fn (CommissionDispute $d) => $d->evidence_path ? 'heroicon-o-paper-clip' : null),
            Tables\Columns\TextColumn::make('period')->label(__('billing.origin_period'))->sortable(),
            Tables\Columns\TextColumn::make('kind')->label(__('billing.kind'))->badge()->formatStateUsing(fn (string $state) => __('billing.kind_'.$state))->color(fn (string $state) => $state === 'dispute' ? 'warning' : 'info'),
            Tables\Columns\TextColumn::make('reason_code')->label(__('billing.reason'))->formatStateUsing(fn (string $state) => __('billing.reason_'.$state))->wrap(),
            Tables\Columns\TextColumn::make('status')->label(__('partner.status'))->badge()->formatStateUsing(fn (string $state) => __('billing.dispute_status_'.$state))->color(fn (string $state) => self::statusColor($state)),
            Tables\Columns\TextColumn::make('decided_at')->label(__('billing.decided_at'))->dateTime('d/m/Y H:i')->placeholder('—'),
        ]));
    }

    /** @return array<int, Section> */
    public static function infolist(): array
    {
        return [
            Section::make(__('billing.dispute'))->columns(2)->schema([
                TextEntry::make('booking.reference')->label(__('booking.reference'))->fontFamily('mono'),
                TextEntry::make('spa.name')->label(__('billing.spa')),
                TextEntry::make('partner.company_name')->label(__('billing.partner')),
                TextEntry::make('period')->label(__('billing.origin_period')),
                TextEntry::make('ledgerEntry.amount')->label(__('billing.commission'))->money(config('hl.currency')),
                TextEntry::make('kind')->label(__('billing.kind'))->badge()->formatStateUsing(fn (string $state) => __('billing.kind_'.$state)),
                TextEntry::make('reason_code')->label(__('billing.reason'))->formatStateUsing(fn (string $state) => __('billing.reason_'.$state)),
                TextEntry::make('status')->label(__('partner.status'))->badge()->formatStateUsing(fn (string $state) => __('billing.dispute_status_'.$state))->color(fn (string $state) => self::statusColor($state)),
                TextEntry::make('comment')->label(__('billing.comment'))->columnSpanFull(),
                TextEntry::make('openedBy.name')->label(__('billing.opened_by'))->placeholder('—'),
                TextEntry::make('created_at')->label(__('billing.opened_at'))->dateTime('d/m/Y H:i'),
            ]),
            Section::make(__('billing.decision'))->columns(2)->visible(fn (CommissionDispute $d) => ! $d->isOpen())->schema([
                TextEntry::make('decidedBy.name')->label(__('billing.decided_by'))->placeholder('—'),
                TextEntry::make('decided_at')->label(__('billing.decided_at'))->dateTime('d/m/Y H:i'),
                TextEntry::make('decision_note')->label(__('billing.decision_note'))->columnSpanFull(),
            ]),
        ];
    }

    public static function evidenceAction(string $class = Tables\Actions\Action::class): mixed
    {
        return $class::make('evidence')->label(__('billing.view_evidence'))->icon('heroicon-o-paper-clip')->color('gray')
            ->visible(fn (CommissionDispute $d) => $d->evidence_path && Storage::disk('local')->exists($d->evidence_path))
            ->action(fn (CommissionDispute $d) => Storage::disk('local')->download($d->evidence_path));
    }
}
