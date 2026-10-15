<?php

namespace App\Filament\Admin\Resources;

use App\Domain\Billing\InvoiceDrafter;
use App\Domain\Billing\InvoiceException;
use App\Domain\Billing\InvoiceIssuer;
use App\Domain\Billing\InvoicePdf;
use App\Domain\Billing\PaymentException;
use App\Domain\Billing\PaymentRecorder;
use App\Domain\Billing\ReceiptPdf;
use App\Filament\Admin\Resources\InvoiceResource\Pages;
use App\Filament\Shared\BillingPeriods;
use App\Models\Invoice;
use App\Models\InvoicePayment;
use App\Models\Partner;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

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
                Tables\Columns\TextColumn::make('paid_amount')->label(__('billing.paid_amount'))->formatStateUsing($fmt)->alignRight()->sortable()->toggleable(),
                Tables\Columns\TextColumn::make('balance')->label(__('billing.balance'))->formatStateUsing($fmt)->alignRight()->sortable()
                    ->color(fn (Invoice $record) => $record->isIssued() && $record->balance > 0 ? ($record->status === Invoice::STATUS_OVERDUE ? 'danger' : 'warning') : 'gray'),
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
                    ->query(fn (Builder $query) => $query->issued()->whereNotIn('status', ['paid', 'cancelled'])->whereDate('due_at', '<', CarbonImmutable::now(config('hl.timezone'))->toDateString())),
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
                ->action(function (Invoice $record, mixed $livewire) {
                    $fresh = app(InvoiceDrafter::class)->draft($record->partner, $record->period);
                    Notification::make()->title($fresh ? __('billing.draft_refreshed') : __('billing.draft_removed'))->success()->send();
                    $livewire->redirect($fresh ? static::getUrl('view', ['record' => $fresh]) : static::getUrl('index'));
                }),
            $class::make('issue')->label(__('billing.issue_action'))->icon('heroicon-o-check-badge')->color('success')
                ->visible(fn (Invoice $record) => $record->isDraft())
                ->requiresConfirmation()->modalDescription(__('billing.issue_confirm'))
                ->action(function (Invoice $record, mixed $livewire) {
                    try {
                        $issued = app(InvoiceIssuer::class)->issue($record, auth()->user());
                        Notification::make()->title(__('billing.issue_done', ['number' => $issued->number]))->success()->send();
                        $livewire->redirect(static::getUrl('view', ['record' => $issued]));
                    } catch (InvoiceException $e) {
                        $body = $e->blockers !== [] ? implode(' · ', array_map(fn ($b) => __('billing.blocker.'.$b), $e->blockers)) : $e->getMessage();
                        Notification::make()->title(__('billing.issue_blocked'))->body($body)->danger()->persistent()->send();
                    }
                }),
            self::pdfAction($class),
            self::recordPaymentAction($class),
        ];
    }

    /** Saisie Admin d'un paiement reçu ; la clé d'opération est générée à l'ouverture du formulaire (un double envoi ne crée qu'un paiement). */
    public static function recordPaymentAction(string $class = Tables\Actions\Action::class): mixed
    {
        return $class::make('recordPayment')->label(__('billing.record_payment'))->icon('heroicon-o-banknotes')->color('primary')
            ->visible(fn (Invoice $record) => $record->isOpenForPayment())
            ->modalHeading(fn (Invoice $record) => __('billing.record_payment').' · '.$record->number)
            ->modalDescription(fn (Invoice $record) => __('billing.balance').' : '.number_format($record->balance, 2, ',', ' ').' '.$record->currency
                .' · '.__('billing.cash_received').' : '.number_format(app(PaymentRecorder::class)->cashReceived($record), 2, ',', ' ').' '.$record->currency
                .' ('.__('billing.cash_limit').' : '.number_format(app(PaymentRecorder::class)->cashLimit(), 2, ',', ' ').' '.$record->currency.')')
            ->form([
                Forms\Components\Hidden::make('idempotency_key')->default(fn () => (string) Str::uuid()),
                Forms\Components\TextInput::make('amount')->label(__('billing.amount'))->numeric()->minValue(0.01)->step(0.01)->required()->suffix(fn (Invoice $record) => $record->currency),
                Forms\Components\DatePicker::make('paid_on')->label(__('billing.paid_on'))->native(false)->maxDate(now(config('hl.timezone')))->default(now(config('hl.timezone'))->toDateString())->required(),
                Forms\Components\Radio::make('method')->label(__('billing.method'))->options(['bank_transfer' => __('billing.method_bank_transfer'), 'cash' => __('billing.method_cash')])->default('bank_transfer')->required()->inline(),
                Forms\Components\TextInput::make('reference')->label(__('billing.reference'))->maxLength(120),
                Forms\Components\Textarea::make('note')->label(__('billing.note'))->rows(2)->maxLength(1000),
            ])
            ->action(function (Invoice $record, array $data, mixed $livewire) {
                try {
                    $payment = app(PaymentRecorder::class)->record($record, auth()->user(), $data);
                    $invoice = $payment->invoice->fresh();
                    Notification::make()->title(__('billing.payment_recorded'))
                        ->body(__('billing.payment_recorded_body', ['amount' => number_format($payment->amount, 2, ',', ' ').' '.$invoice->currency, 'number' => $invoice->number, 'balance' => number_format($invoice->balance, 2, ',', ' ').' '.$invoice->currency]))
                        ->success()->send();
                    $livewire->redirect(static::getUrl('view', ['record' => $invoice]));
                } catch (PaymentException $e) {
                    Notification::make()->title(__('billing.payment_error.forbidden') === $e->getMessage() ? $e->getMessage() : __('billing.payment').' — '.__('billing.locked'))->body($e->getMessage())->danger()->persistent()->send();
                }
            });
    }

    /** Contre-passation d'un paiement (écriture négative liée, motif obligatoire) ; `payment` passé en argument de l'action. */
    public static function reversePaymentAction(string $class = Action::class): mixed
    {
        return $class::make('reversePayment')->label(__('billing.reverse_payment'))->icon('heroicon-o-arrow-uturn-left')->color('danger')->size('xs')->outlined()
            ->modalHeading(__('billing.reverse_payment'))->modalDescription(__('billing.reverse_payment_help'))
            ->form([
                Forms\Components\Hidden::make('idempotency_key')->default(fn () => (string) Str::uuid()),
                Forms\Components\Textarea::make('reason')->label(__('billing.reversal_reason'))->rows(2)->required()->maxLength(500),
            ])
            ->action(function (array $data, array $arguments, mixed $livewire) {
                $payment = InvoicePayment::find((int) ($arguments['payment'] ?? 0));
                if ($payment === null) {
                    Notification::make()->title(__('billing.payment_error.not_found'))->danger()->send();

                    return;
                }
                try {
                    $reversal = app(PaymentRecorder::class)->reverse($payment, auth()->user(), (string) $data['reason'], (string) $data['idempotency_key']);
                    Notification::make()->title(__('billing.payment_reversed'))->body(__('billing.balance').' : '.number_format($reversal->balance_after, 2, ',', ' ').' '.$reversal->currency)->success()->send();
                    $livewire->redirect(static::getUrl('view', ['record' => $reversal->invoice_id]));
                } catch (PaymentException $e) {
                    Notification::make()->title(__('billing.reverse_payment'))->body($e->getMessage())->danger()->persistent()->send();
                }
            });
    }

    /**
     * Téléchargement du reçu d'espèces après vérification de l'empreinte ; commun Admin / Partenaire. Le paiement est résolu par
     * `$resolve(int $id)` fourni par l'appelant, qui applique le filtrage tenant (le partenaire ne voit que ses reçus).
     */
    public static function receiptAction(callable $resolve, string $class = Action::class): mixed
    {
        return $class::make('receipt')->label(__('billing.receipt_download'))->icon('heroicon-o-arrow-down-tray')->color('gray')->size('xs')->outlined()
            ->action(function (array $arguments) use ($resolve) {
                $payment = $resolve((int) ($arguments['payment'] ?? 0));
                if (! $payment instanceof InvoicePayment || $payment->receipt_status !== InvoicePayment::RECEIPT_READY || ! app(ReceiptPdf::class)->verify($payment)) {
                    Notification::make()->title(__('billing.pdf_corrupt'))->danger()->send();

                    return null;
                }

                return Storage::disk(ReceiptPdf::DISK)->download($payment->receipt_path, $payment->receipt_number.'.pdf', ['Cache-Control' => 'no-store, private']);
            });
    }

    /** Téléchargement du PDF après vérification de l'empreinte ; commun Admin / Partenaire (le filtrage tenant est fait par l'appelant). */
    public static function pdfAction(string $class = Tables\Actions\Action::class): mixed
    {
        return $class::make('pdf')->label(__('billing.download_pdf'))->icon('heroicon-o-arrow-down-tray')->color('gray')
            ->visible(fn (Invoice $record) => $record->isIssued() && $record->pdf_status === Invoice::PDF_READY)
            ->action(function (Invoice $record) {
                if (! app(InvoicePdf::class)->verify($record)) {
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
