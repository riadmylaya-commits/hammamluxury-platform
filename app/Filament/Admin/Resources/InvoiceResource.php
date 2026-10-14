<?php

namespace App\Filament\Admin\Resources;

use App\Domain\Billing\InvoiceDrafter;
use App\Domain\Billing\InvoiceException;
use App\Domain\Billing\InvoiceIssuer;
use App\Domain\Billing\InvoicePdf;
use App\Filament\Admin\Resources\InvoiceResource\Pages;
use App\Filament\Shared\BillingPeriods;
use App\Models\Invoice;
use App\Models\Partner;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Storage;

/** Factures mensuelles de commissions : brouillons recalculables, émission validée manuellement, PDF privé vérifié. */
class InvoiceResource extends Resource
{
    protected static ?string $model = Invoice::class;

    protected static ?string $navigationIcon = 'heroicon-o-document-text';

    protected static ?int $navigationSort = 63;

    public static function getNavigationGroup(): ?string
    {
        return __('billing.nav_group');
    }

    public static function getModelLabel(): string
    {
        return __('billing.invoice');
    }

    public static function getPluralModelLabel(): string
    {
        return __('billing.invoices');
    }

    public static function getNavigationBadge(): ?string
    {
        $n = Invoice::draft()->count();

        return $n ? (string) $n : null;
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['partner']);
    }

    public static function table(Table $table): Table
    {
        $fmt = fn ($state, Invoice $record) => number_format((float) $state, 2, ',', ' ').' '.$record->currency;

        return $table->defaultSort('period', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('number')->label(__('billing.invoice_number'))->formatStateUsing(fn (?string $state, Invoice $record) => $record->label())->default('—')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('partner.company_name')->label(__('billing.partner'))->searchable()->sortable(),
                Tables\Columns\TextColumn::make('period')->label(__('billing.period'))->sortable(),
                Tables\Columns\TextColumn::make('status')->label(__('partner.status'))->badge()->formatStateUsing(fn (string $state) => __('billing.invoice_status_'.$state))
                    ->color(fn (string $state) => match ($state) {
                        'draft' => 'gray', 'issued' => 'warning', 'paid' => 'success', 'overdue' => 'danger', default => 'info'
                    }),
                Tables\Columns\TextColumn::make('lines_count')->label(__('billing.invoice_lines'))->alignRight(),
                Tables\Columns\TextColumn::make('total_ttc')->label(__('billing.total_ttc'))->formatStateUsing($fmt)->alignRight()->sortable(),
                Tables\Columns\TextColumn::make('issued_at')->label(__('billing.issued_at'))->dateTime('d/m/Y H:i', config('hl.timezone'))->placeholder('—')->sortable(),
                Tables\Columns\TextColumn::make('due_at')->label(__('billing.due_at'))->date('d/m/Y')->placeholder('—')->sortable(),
                Tables\Columns\TextColumn::make('pdf_status')->label(__('billing.pdf_status'))->badge()->placeholder('—')
                    ->formatStateUsing(fn (string $state) => __('billing.pdf_'.$state))->color(fn (string $state) => match ($state) {
                        'ready' => 'success', 'failed' => 'danger', default => 'gray'
                    }),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')->label(__('partner.status'))
                    ->options(array_combine(Invoice::STATUSES, array_map(fn ($s) => __('billing.invoice_status_'.$s), Invoice::STATUSES))),
                Tables\Filters\SelectFilter::make('partner_id')->label(__('billing.partner'))->options(fn () => Partner::orderBy('company_name')->pluck('company_name', 'id')->all())->searchable(),
                Tables\Filters\SelectFilter::make('period')->label(__('billing.period'))->options(fn () => BillingPeriods::options(24)),
                Tables\Filters\Filter::make('overdue')->label(__('billing.invoice_status_overdue'))
                    ->query(fn (Builder $q) => $q->issued()->whereNotIn('status', ['paid', 'cancelled'])->whereDate('due_at', '<', CarbonImmutable::now(config('hl.timezone'))->toDateString())),
            ])
            ->actions([Tables\Actions\ViewAction::make(), ...self::invoiceActions()])
            ->emptyStateHeading(__('billing.no_invoices'));
    }

    /** @return list<Tables\Actions\Action|Action> */
    public static function invoiceActions(string $class = Tables\Actions\Action::class): array
    {
        return [
            $class::make('refresh')->label(__('billing.refresh_draft'))->icon('heroicon-o-arrow-path')->color('gray')
                ->visible(fn (Invoice $record) => $record->isDraft())
                ->action(function (Invoice $i, mixed $livewire) {
                    $fresh = app(InvoiceDrafter::class)->draft($record->partner, $record->period);
                    Notification::make()->title($fresh ? __('billing.draft_refreshed') : __('billing.draft_removed'))->success()->send();
                    $livewire->redirect($fresh ? static::getUrl('view', ['record' => $fresh]) : static::getUrl('index'));
                }),
            $class::make('issue')->label(__('billing.issue_action'))->icon('heroicon-o-check-badge')->color('success')
                ->visible(fn (Invoice $record) => $record->isDraft())
                ->requiresConfirmation()->modalDescription(__('billing.issue_confirm'))
                ->action(function (Invoice $i, mixed $livewire) {
                    try {
                        $issued = app(InvoiceIssuer::class)->issue($i, auth()->user());
                        Notification::make()->title(__('billing.issue_done', ['number' => $issued->number]))->success()->send();
                        $livewire->redirect(static::getUrl('view', ['record' => $issued]));
                    } catch (InvoiceException $e) {
                        $body = $e->blockers !== [] ? implode(' · ', array_map(fn ($b) => __('billing.blocker.'.$b), $e->blockers)) : $e->getMessage();
                        Notification::make()->title(__('billing.issue_blocked'))->body($body)->danger()->persistent()->send();
                    }
                }),
            self::pdfAction($class),
        ];
    }

    /** Téléchargement du PDF après vérification de l'empreinte ; commun Admin / Partenaire (le filtrage tenant est fait par l'appelant). */
    public static function pdfAction(string $class = Tables\Actions\Action::class): mixed
    {
        return $class::make('pdf')->label(__('billing.download_pdf'))->icon('heroicon-o-arrow-down-tray')->color('gray')
            ->visible(fn (Invoice $record) => $record->isIssued() && $record->pdf_status === Invoice::PDF_READY)
            ->action(function (Invoice $record) {
                if (! app(InvoicePdf::class)->verify($i)) {
                    Notification::make()->title(__('billing.pdf_corrupt'))->danger()->send();

                    return null;
                }

                return Storage::disk(InvoicePdf::DISK)->download($record->pdf_path, $record->number.'.pdf', ['Cache-Control' => 'no-store, private']);
            });
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListInvoices::route('/'),
            'view' => Pages\ViewInvoice::route('/{record}'),
        ];
    }
}
