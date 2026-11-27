<?php

namespace App\Filament\Admin\Resources\InvoiceResource\Pages;

use App\Domain\Billing\BillingSettings;
use App\Domain\Billing\InvoiceDrafter;
use App\Filament\Admin\Resources\InvoiceResource;
use App\Models\ActivityLog;
use App\Models\Invoice;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\Components\Tab;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;

class ListInvoices extends ListRecords
{
    protected static string $resource = InvoiceResource::class;

    public function getTabs(): array
    {
        return [
            'all' => Tab::make(__('billing.all')),
            'draft' => Tab::make(__('billing.drafts'))->modifyQueryUsing(fn (Builder $query) => $query->draft()),
            'issued' => Tab::make(__('billing.to_pay'))->modifyQueryUsing(fn (Builder $query) => $query->issued()->whereNotIn('status', ['paid', 'cancelled'])),
            'paid' => Tab::make(__('billing.invoice_status_paid'))->modifyQueryUsing(fn (Builder $query) => $query->where('status', 'paid')),
        ];
    }

    /** Mois clos facturables : du premier mois facturable au mois précédent. @return array<string, string> */
    public static function closedPeriods(): array
    {
        $start = app(BillingSettings::class)->startPeriod();
        $out = [];
        if ($start === null) {
            return $out;
        }
        $m = CarbonImmutable::now(config('hl.timezone'))->startOfMonth()->subMonthNoOverflow();
        for ($i = 0; $i < 24 && $m->gte($start); $i++, $m = $m->subMonthNoOverflow()) {
            $out[$m->format('Y-m')] = ucfirst($m->translatedFormat('F Y'));
        }

        return $out;
    }

    protected function getHeaderActions(): array
    {
        return [
            // Prépare uniquement des brouillons : aucun numéro, aucune émission, aucun PDF, aucun envoi.
            Action::make('generateDrafts')->label(__('billing.generate_drafts'))->icon('heroicon-o-document-plus')->color('gray')
                ->visible(fn () => auth()->user()?->role === 'admin')
                ->disabled(fn () => self::closedPeriods() === [])
                ->tooltip(fn () => self::closedPeriods() === [] ? __('billing.generate_drafts_unavailable') : null)
                ->form([Select::make('period')->label(__('billing.period'))->options(fn () => self::closedPeriods())->in(fn () => array_keys(self::closedPeriods()))->required()->native(false)])
                ->requiresConfirmation()
                ->modalHeading(__('billing.generate_drafts'))
                ->modalDescription(__('billing.generate_drafts_help'))
                ->action(function (array $data) {
                    abort_unless(auth()->user()?->role === 'admin', 403);
                    abort_unless(array_key_exists((string) $data['period'], self::closedPeriods()), 422);
                    // draftAll renvoie aussi les factures déjà émises du mois : seuls les brouillons comptent.
                    $drafts = array_values(array_filter(app(InvoiceDrafter::class)->draftAll($data['period']), fn ($i) => $i->isDraft()));
                    ActivityLog::record('billing.drafts_generated', null, ['period' => $data['period'], 'drafts' => count($drafts)], 'admin');
                    Notification::make()->title(trans_choice('billing.generate_drafts_done', count($drafts), ['count' => count($drafts)]))->success()->send();
                }),
        ];
    }

    protected function getHeaderWidgets(): array
    {
        return [];
    }

    public function getSubheading(): ?string
    {
        $n = Invoice::draft()->count();

        return $n ? __('billing.drafts').' : '.$n : null;
    }
}
