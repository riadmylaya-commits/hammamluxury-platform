<?php

namespace App\Filament\Admin\Resources;

use App\Domain\Phone\PhoneNumber;
use App\Filament\Admin\Resources\PartnerResource\Pages;
use App\Filament\Forms\Components\PhoneField;
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
                Forms\Components\TextInput::make('legal_form')->label(__('admin.legal_form'))->maxLength(60),
                Forms\Components\TextInput::make('tax_id')->label(__('admin.tax_id'))->maxLength(60),
                Forms\Components\TextInput::make('registry_number')->label(__('admin.registry_number'))->maxLength(60),
                PhoneField::make('contact_phone', __('partner.phone'))->columnSpanFull(),
            ])->columns(2),
            Forms\Components\Section::make(__('admin.validation'))->schema([
                Forms\Components\Select::make('status')->label(__('partner.status'))->options(self::statuses())->required(),
                Forms\Components\TextInput::make('commission_pct')->label(__('admin.commission_pct'))->numeric()->minValue(0)->maxValue(100)->suffix('%')
                    ->placeholder(__('partner.default_value', ['v' => config('hl.default_commission_pct').' %'])),
                Forms\Components\Textarea::make('status_note')->label(__('admin.status_note'))->rows(3)->columnSpanFull(),
            ])->columns(2),
        ]);
    }

    public static function statuses(): array
    {
        return ['pending' => __('admin.p_pending'), 'approved' => __('admin.p_approved'), 'suspended' => __('admin.p_suspended')];
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('company_name')->label(__('partner.company_name'))->searchable()->weight('bold')
                    ->description(fn (Partner $p) => $p->user?->email),
                Tables\Columns\TextColumn::make('user.name')->label(__('admin.contact')),
                Tables\Columns\TextColumn::make('contact_phone')->label(__('partner.phone'))->formatStateUsing(fn (?string $state) => PhoneNumber::format($state)),
                Tables\Columns\TextColumn::make('spas_count')->counts('spas')->label(__('admin.spas')),
                Tables\Columns\TextColumn::make('commission_pct')->label(__('admin.commission_pct'))->formatStateUsing(fn (Partner $p) => $p->commissionPct().' %'),
                Tables\Columns\TextColumn::make('status')->label(__('partner.status'))->badge()
                    ->formatStateUsing(fn ($state) => self::statuses()[$state] ?? $state)
                    ->color(fn ($state) => match ($state) {
                        'approved' => 'success', 'pending' => 'warning', default => 'danger'
                    }),
                Tables\Columns\TextColumn::make('created_at')->label(__('admin.registered'))->since(),
            ])
            ->filters([Tables\Filters\SelectFilter::make('status')->options(self::statuses())])
            ->actions([
                Tables\Actions\Action::make('approve')->label(__('admin.approve'))->icon('heroicon-o-check')->color('success')
                    ->visible(fn (Partner $p) => $p->status !== 'approved')->requiresConfirmation()
                    ->action(function (Partner $p) {
                        $p->update(['status' => 'approved']);
                        Notification::make()->title(__('admin.approved_ok'))->success()->send();
                    }),
                Tables\Actions\EditAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPartners::route('/'),
            'edit' => Pages\EditPartner::route('/{record}/edit'),
        ];
    }
}
