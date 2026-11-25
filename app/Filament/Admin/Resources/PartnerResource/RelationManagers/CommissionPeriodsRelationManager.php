<?php

namespace App\Filament\Admin\Resources\PartnerResource\RelationManagers;

use App\Models\ActivityLog;
use App\Models\Partner;
use App\Models\PartnerCommissionPeriod;
use Carbon\CarbonImmutable;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

/** Périodes commerciales d'un partenaire : mois gratuit, taux promotionnel, dates libres. Les réservations existantes ne sont jamais recalculées. */
class CommissionPeriodsRelationManager extends RelationManager
{
    protected static string $relationship = 'commissionPeriods';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('admin.periods');
    }

    public static function getModelLabel(): string
    {
        return __('admin.period');
    }

    public function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\TextInput::make('label')->label(__('admin.period_label'))->required()->maxLength(120),
            Forms\Components\TextInput::make('commission_pct')->label(__('admin.commission_pct'))->numeric()->minValue(0)->maxValue(100)->suffix('%')->required(),
            Forms\Components\DatePicker::make('starts_on')->label(__('admin.period_starts'))->required(),
            Forms\Components\DatePicker::make('ends_on')->label(__('admin.period_ends'))->afterOrEqual('starts_on'),
        ])->columns(2);
    }

    /** Refuse un chevauchement : à une date donnée, un seul taux de période. */
    public static function assertNoOverlap(Partner $partner, string $from, ?string $to, ?int $ignoreId = null): void
    {
        $from = substr($from, 0, 10);
        $to = $to === null ? null : substr($to, 0, 10);
        $clash = $partner->commissionPeriods()->get()->first(fn (PartnerCommissionPeriod $p) => $p->id !== $ignoreId && $p->overlaps($from, $to));
        if ($clash) {
            throw ValidationException::withMessages(['mountedTableActionsData.0.starts_on' => __('admin.period_overlap'), 'starts_on' => __('admin.period_overlap')]);
        }
    }

    /** Offre de lancement : N mois gratuits puis M mois au taux promotionnel ; ensuite le taux hors période (standard) reprend seul. */
    public static function applyLaunchOffer(Partner $partner, string $start, int $freeMonths, int $promoMonths, float $promoPct): void
    {
        $from = CarbonImmutable::parse(substr($start, 0, 10));
        $rows = [];
        if ($freeMonths > 0) {
            $rows[] = ['label' => __('admin.launch_free_label'), 'commission_pct' => 0, 'starts_on' => $from, 'ends_on' => $from->addMonthsNoOverflow($freeMonths)->subDay()];
            $from = $from->addMonthsNoOverflow($freeMonths);
        }
        if ($promoMonths > 0) {
            $rows[] = ['label' => __('admin.launch_promo_label'), 'commission_pct' => $promoPct, 'starts_on' => $from, 'ends_on' => $from->addMonthsNoOverflow($promoMonths)->subDay()];
        }
        foreach ($rows as $r) {
            self::assertNoOverlap($partner, $r['starts_on']->format('Y-m-d'), $r['ends_on']->format('Y-m-d'));
        }
        foreach ($rows as $r) {
            $p = $partner->commissionPeriods()->create($r + ['created_by' => auth()->id()]);
            self::log('partner.commission_period_created', $p);
        }
    }

    private static function log(string $action, PartnerCommissionPeriod $p, array $extra = []): void
    {
        ActivityLog::record($action, $p->partner, ['label' => $p->label, 'pct' => $p->commission_pct, 'from' => $p->starts_on->format('Y-m-d'), 'to' => $p->ends_on?->format('Y-m-d')] + $extra);
    }

    public function table(Table $table): Table
    {
        $today = fn () => CarbonImmutable::now(config('hl.timezone'));
        $state = fn (PartnerCommissionPeriod $p) => $p->covers($today()) ? 'current' : ($p->starts_on->format('Y-m-d') > $today()->format('Y-m-d') ? 'future' : 'past');

        return $table
            ->description(__('admin.periods_help'))
            ->defaultSort('starts_on')
            ->columns([
                Tables\Columns\TextColumn::make('state')->label(__('admin.period_state'))->badge()->state($state)
                    ->formatStateUsing(fn (string $state) => __('admin.period_'.$state))
                    ->color(fn (string $state) => ['current' => 'success', 'future' => 'warning', 'past' => 'gray'][$state]),
                Tables\Columns\TextColumn::make('label')->label(__('admin.period_label'))->wrap(),
                Tables\Columns\TextColumn::make('commission_pct')->label(__('admin.commission_pct'))->suffix(' %')->weight('bold'),
                Tables\Columns\TextColumn::make('starts_on')->label(__('admin.period_starts'))->date('d/m/Y'),
                Tables\Columns\TextColumn::make('ends_on')->label(__('admin.period_ends'))->date('d/m/Y')->placeholder(__('admin.period_no_end')),
            ])
            ->emptyStateHeading(__('admin.no_periods'))
            ->headerActions([
                Tables\Actions\Action::make('launchOffer')->label(__('admin.launch_offer'))->icon('heroicon-o-gift')->color('gray')
                    ->modalDescription(__('admin.launch_offer_help'))
                    ->form([
                        Forms\Components\DatePicker::make('start')->label(__('admin.launch_start'))->required()->default(fn () => $today()->format('Y-m-d')),
                        Forms\Components\TextInput::make('free_months')->label(__('admin.launch_free_months'))->integer()->minValue(0)->maxValue(24)->default(1)->required(),
                        Forms\Components\TextInput::make('promo_months')->label(__('admin.launch_promo_months'))->integer()->minValue(0)->maxValue(36)->default(0)->required(),
                        Forms\Components\TextInput::make('promo_pct')->label(__('admin.launch_promo_pct'))->numeric()->minValue(0)->maxValue(100)->suffix('%')->default(10)->required(),
                    ])
                    ->action(fn (array $data) => self::applyLaunchOffer($this->getOwnerRecord(), $data['start'], (int) $data['free_months'], (int) $data['promo_months'], (float) $data['promo_pct'])),
                Tables\Actions\CreateAction::make()
                    ->mutateFormDataUsing(function (array $data) {
                        self::assertNoOverlap($this->getOwnerRecord(), $data['starts_on'], $data['ends_on'] ?? null);

                        return $data + ['created_by' => auth()->id()];
                    })
                    ->after(fn (PartnerCommissionPeriod $record) => self::log('partner.commission_period_created', $record)),
            ])
            ->actions([
                Tables\Actions\EditAction::make()->iconButton()
                    ->mutateFormDataUsing(function (array $data, PartnerCommissionPeriod $record) {
                        self::assertNoOverlap($this->getOwnerRecord(), $data['starts_on'], $data['ends_on'] ?? null, $record->id);

                        return $data;
                    })
                    ->after(fn (PartnerCommissionPeriod $record) => self::log('partner.commission_period_updated', $record)),
                Tables\Actions\DeleteAction::make()->iconButton()
                    ->before(fn (PartnerCommissionPeriod $record) => self::log('partner.commission_period_deleted', $record)),
            ]);
    }
}
