<?php

namespace App\Filament\Admin\Pages;

use App\Domain\Billing\CreditNoteException;
use App\Domain\Billing\PartnerCreditService;
use App\Filament\Admin\Resources\InvoiceResource;
use App\Models\Invoice;
use App\Models\PartnerCredit;
use Filament\Actions\Action;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;

/** Crédits partenaires : imputation sur une facture ouverte ou remboursement déclaratif, décidés manuellement par l'Admin et tracés. */
class PartnerCreditsPage extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-wallet';

    protected static ?int $navigationSort = 65;

    protected static string $view = 'filament.admin.pages.partner-credits';

    public static function getNavigationGroup(): ?string
    {
        return __('billing.nav_group');
    }

    public static function getNavigationLabel(): string
    {
        return __('billing.credits');
    }

    public static function getNavigationBadge(): ?string
    {
        $n = PartnerCredit::open()->count();

        return $n > 0 ? (string) $n : null;
    }

    public function getTitle(): string
    {
        return __('billing.credits');
    }

    public function getSubheading(): ?string
    {
        return __('billing.credit_refund_help');
    }

    /** @return Collection<int, PartnerCredit> */
    public function credits(): Collection
    {
        return PartnerCredit::with(['partner', 'creditNote.invoice', 'overpayment.invoice', 'applications.invoice', 'applications.decidedBy', 'closedBy'])
            ->orderByRaw("status = 'open' DESC")->orderByDesc('id')->get();
    }

    public function invoiceUrl(int $id): string
    {
        return InvoiceResource::getUrl('view', ['record' => $id]);
    }

    public function applyAction(): Action
    {
        return Action::make('applyCredit')->label(__('billing.credit_apply'))->icon('heroicon-o-arrow-right-circle')->color('primary')->size('xs')
            ->modalHeading(__('billing.credit_apply'))->modalDescription(__('billing.credit_apply_help'))
            ->form(function (array $arguments) {
                $credit = PartnerCredit::find((int) ($arguments['credit'] ?? 0));
                $invoices = $credit === null ? collect() : Invoice::openForPayment()->where('partner_id', $credit->partner_id)->where('balance', '>', 0)->orderBy('issued_at')->get();

                return [
                    Forms\Components\Hidden::make('idempotency_key')->default(fn () => (string) Str::uuid()),
                    Forms\Components\Select::make('invoice_id')->label(__('billing.credit_target_invoice'))->required()
                        ->options($invoices->mapWithKeys(fn (Invoice $i) => [$i->id => $i->number.' · '.__('billing.balance').' '.number_format($i->balance, 2, ',', ' ').' '.$i->currency])->all()),
                    Forms\Components\TextInput::make('amount')->label(__('billing.amount'))->numeric()->minValue(0.01)->step(0.01)->required()
                        ->default($credit?->remaining())->helperText(__('billing.credit_remaining').' : '.number_format($credit?->remaining() ?? 0, 2, ',', ' ')),
                ];
            })
            ->action(function (array $data, array $arguments) {
                $credit = PartnerCredit::find((int) ($arguments['credit'] ?? 0));
                $invoice = Invoice::find((int) ($data['invoice_id'] ?? 0));
                if ($credit === null || $invoice === null) {
                    Notification::make()->title(__('billing.credit_note_error.not_found'))->danger()->send();

                    return;
                }
                try {
                    $a = app(PartnerCreditService::class)->apply($credit, $invoice, auth()->user(), (float) $data['amount'], (string) $data['idempotency_key']);
                    Notification::make()->title(__('billing.credit_applied'))->body($invoice->number.' · '.number_format($a->amount, 2, ',', ' ').' '.$credit->currency)->success()->send();
                    $this->redirect(static::getUrl());
                } catch (CreditNoteException $e) {
                    Notification::make()->title(__('billing.credit_refused'))->body($e->getMessage())->danger()->persistent()->send();
                }
            });
    }

    public function refundAction(): Action
    {
        return Action::make('refundCredit')->label(__('billing.credit_refund'))->icon('heroicon-o-banknotes')->color('gray')->size('xs')->outlined()
            ->modalHeading(__('billing.credit_refund'))->modalDescription(__('billing.credit_refund_help'))
            ->form(function (array $arguments) {
                $credit = PartnerCredit::find((int) ($arguments['credit'] ?? 0));

                return [
                    Forms\Components\Hidden::make('idempotency_key')->default(fn () => (string) Str::uuid()),
                    Forms\Components\TextInput::make('amount')->label(__('billing.amount'))->numeric()->minValue(0.01)->step(0.01)->required()
                        ->default($credit?->remaining())->helperText(__('billing.credit_remaining').' : '.number_format($credit?->remaining() ?? 0, 2, ',', ' ')),
                    Forms\Components\DatePicker::make('refund_on')->label(__('billing.refund_on'))->required()->maxDate(now())->default(now()),
                    Forms\Components\Select::make('method')->label(__('billing.refund_method'))->required()
                        ->options(['bank_transfer' => __('billing.refund_method_bank_transfer'), 'cash' => __('billing.refund_method_cash'), 'other' => __('billing.refund_method_other')]),
                    Forms\Components\TextInput::make('reference')->label(__('billing.refund_reference'))->required()->maxLength(120),
                    Forms\Components\Textarea::make('note')->label(__('billing.note'))->rows(2)->maxLength(1000),
                ];
            })
            ->action(function (array $data, array $arguments) {
                $credit = PartnerCredit::find((int) ($arguments['credit'] ?? 0));
                if ($credit === null) {
                    Notification::make()->title(__('billing.credit_note_error.not_found'))->danger()->send();

                    return;
                }
                try {
                    $a = app(PartnerCreditService::class)->refund($credit, auth()->user(), ['amount' => $data['amount'], 'refund_on' => (string) $data['refund_on'], 'method' => (string) $data['method'],
                        'reference' => $data['reference'] ?? null, 'note' => $data['note'] ?? null], (string) $data['idempotency_key']);
                    Notification::make()->title(__('billing.credit_refunded'))->body(number_format($a->amount, 2, ',', ' ').' '.$credit->currency)->success()->send();
                    $this->redirect(static::getUrl());
                } catch (CreditNoteException $e) {
                    Notification::make()->title(__('billing.credit_refused'))->body($e->getMessage())->danger()->persistent()->send();
                }
            });
    }
}
