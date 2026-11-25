<?php

namespace App\Filament\Admin\Resources;

use App\Domain\Billing\BillingGate;
use App\Domain\Phone\PhoneNumber;
use App\Filament\Admin\Resources\PartnerResource\Pages;
use App\Filament\Admin\Resources\PartnerResource\RelationManagers;
use App\Filament\Forms\Components\PhoneField;
use App\Filament\Partner\Pages\BillingProfile;
use App\Models\ActivityLog;
use App\Models\Partner;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class PartnerResource extends Resource
{
    protected static ?string $model = Partner::class;

    protected static ?string $navigationIcon = 'heroicon-o-briefcase';

    protected static ?int $navigationSort = 10;

    public static function getModelLabel(): string
    {
        return __('admin.partner');
    }

    public static function getPluralModelLabel(): string
    {
        return __('admin.partners');
    }

    public static function getNavigationBadge(): ?string
    {
        $n = Partner::where('status', 'pending')->count();

        return $n ? (string) $n : null;
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make(__('admin.company'))->schema([
                Forms\Components\TextInput::make('company_name')->label(__('partner.company_name'))->required()->maxLength(190),
                PhoneField::make('contact_phone', __('partner.phone')),
            ])->columns(2),
            Forms\Components\Section::make(__('billing.partner_section'))->description(__('billing.partner_section_help'))
                ->schema(BillingProfile::schema(withCompany: false))->columns(2),
            Forms\Components\Section::make(__('admin.validation'))->schema([
                Forms\Components\Select::make('status')->label(__('partner.status'))->options(self::statuses())->required(),
                Forms\Components\TextInput::make('commission_pct')->label(__('admin.commission_pct'))->numeric()->minValue(0)->maxValue(100)->suffix('%')
                    ->placeholder(__('partner.default_value', ['v' => config('hl.default_commission_pct').' %']))
                    ->helperText(__('admin.commission_pct_help')),
                Forms\Components\Placeholder::make('commercial_now')->label(__('admin.rate_now'))
                    ->content(fn (?Partner $record) => $record ? self::rateNow($record) : '—'),
                Forms\Components\Placeholder::make('commercial_next')->label(__('admin.rate_next'))
                    ->content(fn (?Partner $record) => $record ? self::rateNext($record) : '—'),
                Forms\Components\Textarea::make('status_note')->label(__('admin.status_note'))->rows(3)->columnSpanFull(),
            ])->columns(2),
        ]);
    }

    public static function rateNow(Partner $p): string
    {
        if (! $p->isCommercial()) {
            return '0 % — '.__('admin.commercial_referenced');
        }

        return $p->commissionPct().' % — '.($p->commissionPeriodOn()?->label ?? __('admin.commission_standard'));
    }

    public static function rateNext(Partner $p): string
    {
        $c = $p->commissionChanges()[0] ?? null;
        if (! $c) {
            return __('admin.rate_next_none');
        }
        $s = __('admin.rate_next_value', ['pct' => $c['to'], 'date' => $c['on']->format('d/m/Y'), 'days' => $c['days']]);

        return $p->isCommercial() ? $s : $s.' — '.__('admin.rate_next_referenced');
    }

    public static function statuses(): array
    {
        return ['pending' => __('admin.p_pending'), 'approved' => __('admin.p_approved'), 'suspended' => __('admin.p_suspended')];
    }

    public static function mobileCard(Partner $p): string
    {
        return implode('', [
            '<div><strong>'.e($p->company_name).'</strong></div>',
            '<div>'.e($p->isCommercial() ? __('admin.commercial_partner') : __('admin.commercial_referenced')).' · '.e(self::rateNow($p)).'</div>',
            '<div>'.e(self::statuses()[$p->status] ?? $p->status).'</div>',
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('m_card')->label('')->state(fn (Partner $p) => self::mobileCard($p))->html()->wrap()->hiddenFrom('xl'),
                Tables\Columns\TextColumn::make('company_name')->visibleFrom('xl')->label(__('partner.company_name'))->searchable()->weight('bold')->wrap()
                    ->description(fn (Partner $p) => $p->user?->email),
                Tables\Columns\TextColumn::make('user.name')->visibleFrom('xl')->toggleable(isToggledHiddenByDefault: true)->label(__('admin.contact')),
                Tables\Columns\TextColumn::make('contact_phone')->visibleFrom('xl')->toggleable(isToggledHiddenByDefault: true)->label(__('partner.phone'))->formatStateUsing(fn (?string $state) => PhoneNumber::format($state)),
                Tables\Columns\TextColumn::make('spas_count')->visibleFrom('xl')->counts('spas')->label(__('admin.spas')),
                Tables\Columns\TextColumn::make('commercial_type')->visibleFrom('xl')->label(__('admin.commercial_type'))->badge()
                    ->state(fn (Partner $p) => $p->isCommercial() ? __('admin.commercial_partner') : __('admin.commercial_referenced'))
                    ->color(fn (Partner $p) => $p->isCommercial() ? 'success' : 'gray'),
                Tables\Columns\TextColumn::make('commission_pct')->visibleFrom('xl')->label(__('admin.commission_pct'))
                    ->state(fn (Partner $p) => $p->isCommercial() ? $p->commissionPct().' %' : '0 %')
                    ->description(fn (Partner $p) => $p->isCommercial() ? ($p->commissionPeriodOn()?->label ?? __('admin.commission_standard')) : __('admin.commission_referenced_note')),
                Tables\Columns\IconColumn::make('billing_ready')->visibleFrom('xl')->label(__('billing.billing_ready'))->boolean()
                    ->state(fn (Partner $p) => app(BillingGate::class)->partnerReady($p))
                    ->tooltip(fn (Partner $p) => app(BillingGate::class)->partnerReady($p) ? __('billing.billing_ready_yes') : __('billing.billing_ready_no')),
                Tables\Columns\TextColumn::make('status')->visibleFrom('xl')->label(__('partner.status'))->badge()
                    ->formatStateUsing(fn ($state) => self::statuses()[$state] ?? $state)
                    ->color(fn ($state) => match ($state) {
                        'approved' => 'success', 'pending' => 'warning', default => 'danger'
                    }),
                Tables\Columns\TextColumn::make('created_at')->visibleFrom('xl')->toggleable(isToggledHiddenByDefault: true)->label(__('admin.registered'))->since(),
            ])
            ->filters([Tables\Filters\SelectFilter::make('status')->options(self::statuses())])
            ->actions([
                Tables\Actions\Action::make('approve')->label(__('admin.approve'))->icon('heroicon-o-check')->color('success')
                    ->visible(fn (Partner $p) => $p->status !== 'approved')->requiresConfirmation()
                    ->action(function (Partner $p) {
                        $p->update(['status' => 'approved']);
                        ActivityLog::record('partner.approved', $p, ['company' => $p->company_name]);
                        Notification::make()->title(__('admin.approved_ok'))->success()->send();
                    }),
                Tables\Actions\EditAction::make(),
            ]);
    }

    public static function getRelations(): array
    {
        return [RelationManagers\CommissionPeriodsRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPartners::route('/'),
            'edit' => Pages\EditPartner::route('/{record}/edit'),
        ];
    }
}
