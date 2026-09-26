<?php

namespace App\Filament\Admin\Resources;

use App\Domain\Catalogue\PublicationChecklist;
use App\Domain\Partner\OnboardingService;
use App\Filament\Admin\Resources\SpaResource\Pages;
use App\Filament\Admin\Resources\SpaResource\RelationManagers;
use App\Filament\Partner\Forms\SpaForm;
use App\Models\Spa;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\HtmlString;

class SpaResource extends Resource
{
    protected static ?string $model = Spa::class;

    protected static ?string $navigationIcon = 'heroicon-o-building-storefront';

    protected static ?int $navigationSort = 20;

    public static function getModelLabel(): string
    {
        return __('admin.spa');
    }

    public static function getPluralModelLabel(): string
    {
        return __('admin.spas');
    }

    public static function getNavigationBadge(): ?string
    {
        $n = Spa::where('status', 'pending')->count();

        return $n ? (string) $n : null;
    }

    public static function statuses(): array
    {
        return collect(Spa::STATUSES)->mapWithKeys(fn ($s) => [$s => __("partner.spa_status_$s")])->all();
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make(__('admin.validation'))->schema([
                Forms\Components\Select::make('status')->label(__('partner.status'))->options(self::statuses())->required(),
                Forms\Components\TextInput::make('slug')->label('Slug')->required()->maxLength(120)->unique(ignoreRecord: true),
                Forms\Components\Textarea::make('status_note')->label(__('admin.status_note'))->rows(3)->helperText(__('admin.status_note_help'))->columnSpanFull(),
            ])->columns(2),
            Forms\Components\Section::make(__('partner.license_section'))->description(__('admin.license_admin_help'))->schema([
                Forms\Components\TextInput::make('license_number')->label(__('partner.license_number'))->maxLength(60),
                Forms\Components\Select::make('license_authority')->label(__('partner.license_authority'))->options(SpaForm::licenseAuthorities())->native(false)->placeholder('—')->live(),
                Forms\Components\TextInput::make('license_authority_other')->label(__('partner.license_authority_other_name'))->maxLength(120)
                    ->visible(fn (Forms\Get $get) => $get('license_authority') === 'other'),
                Forms\Components\Placeholder::make('license_warning')->label('')->columnSpanFull()
                    ->visible(fn (Spa $s) => $s->licenseExpected() && blank($s->license_number))
                    ->content(new HtmlString('<span class="font-medium" style="color: rgb(var(--warning-600))">'.e(__('admin.license_missing_warning')).'</span>')),
            ])->columns(2),
            Forms\Components\Section::make(__('admin.checklist'))->schema([
                Forms\Components\Placeholder::make('checks')->label('')->content(fn (Spa $s) => self::checklist($s)),
            ]),
        ]);
    }

    public static function checklist(Spa $spa): HtmlString
    {
        $html = '<ul class="space-y-1">';
        foreach (PublicationChecklist::checks($spa) as $label => $ok) {
            $html .= '<li class="'.($ok ? 'text-success-600' : 'text-danger-600 font-medium').'">'.($ok ? '[OK]' : '[!!]').' '.e($label).'</li>';
        }
        if ($spa->licenseExpected() && blank($spa->license_number)) {
            $html .= '<li class="font-medium" style="color: rgb(var(--warning-600))">[ ? ] '.e(__('admin.license_missing_warning')).'</li>';
        }
        if (! PublicationChecklist::passes($spa)) {
            $html .= '<li class="font-semibold text-danger-600">'.e(__('admin.checklist_blocking')).'</li>';
        }

        return new HtmlString($html.'</ul>');
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['partner', 'photos']))
            ->columns([
                Tables\Columns\ImageColumn::make('cover')->label('')->getStateUsing(fn (Spa $s) => $s->coverPhoto()?->url())->circular(),
                Tables\Columns\TextColumn::make('name')->label(__('admin.spa'))->searchable()->weight('bold')
                    ->description(fn (Spa $s) => $s->area ? "$s->area · $s->city" : $s->city),
                Tables\Columns\TextColumn::make('partner.company_name')->label(__('admin.partner'))->searchable(),
                Tables\Columns\TextColumn::make('treatments_count')->counts('treatments')->label(__('partner.treatments')),
                Tables\Columns\TextColumn::make('bookings_count')->counts('bookings')->label(__('partner.bookings')),
                Tables\Columns\TextColumn::make('rating')->label(__('admin.rating'))->placeholder('—'),
                Tables\Columns\TextColumn::make('license_number')->label(__('admin.license_col'))->toggleable()->badge()
                    ->getStateUsing(fn (Spa $s) => $s->license_number ?: ($s->licenseExpected() ? __('admin.license_missing') : null))->placeholder('—')
                    ->color(fn (Spa $s) => blank($s->license_number) ? 'warning' : 'gray')
                    ->description(fn (Spa $s) => $s->licenseAuthorityLabel()),
                Tables\Columns\TextColumn::make('status')->label(__('partner.status'))->badge()
                    ->formatStateUsing(fn ($state) => self::statuses()[$state] ?? $state)
                    ->color(fn ($state) => match ($state) {
                        'published' => 'success', 'pending' => 'warning', 'suspended' => 'danger', default => 'gray'
                    }),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')->options(self::statuses()),
                Tables\Filters\Filter::make('without_license')->label(__('admin.filter_without_license'))->toggle()
                    ->query(fn (Builder $query) => $query->whereNull('license_number')),
                Tables\Filters\Filter::make('hammam_without_license')->label(__('admin.filter_hammam_without_license'))->toggle()
                    ->query(fn (Builder $query) => $query->whereNull('license_number')->whereIn('category', Spa::LICENSE_EXPECTED_CATEGORIES)),
            ])
            ->actions([
                Tables\Actions\Action::make('publish')->label(__('admin.publish'))->icon('heroicon-o-eye')->color('success')
                    ->visible(fn (Spa $s) => $s->status !== 'published')->requiresConfirmation()
                    ->modalContent(fn (Spa $s) => self::checklist($s))
                    ->modalSubmitAction(fn ($action, Spa $s) => $action->disabled(! PublicationChecklist::passes($s))->extraAttributes(fn () => PublicationChecklist::passes($s) ? [] : ['disabled' => 'disabled']))
                    ->action(function (Spa $s) {
                        if (! PublicationChecklist::passes($s)) {
                            Notification::make()->title(__('admin.checklist_blocking'))->body(implode(' · ', PublicationChecklist::failures($s)))->danger()->send();

                            return;
                        }
                        $s->refreshPriceFrom();
                        $s->update(['status' => 'published', 'published_at' => $s->published_at ?? now(), 'status_note' => null]);
                        OnboardingService::notifyDecision($s, 'published');
                        Notification::make()->title(__('admin.published_ok'))->success()->send();
                    }),
                Tables\Actions\Action::make('refuse')->label(__('admin.refuse'))->icon('heroicon-o-x-mark')->color('danger')
                    ->visible(fn (Spa $s) => $s->status === 'pending')
                    ->form([Forms\Components\Textarea::make('status_note')->label(__('admin.refuse_reason'))->required()->rows(4)->maxLength(2000)])
                    ->action(function (Spa $s, array $data) {
                        $s->update(['status' => 'draft', 'status_note' => $data['status_note']]);
                        OnboardingService::notifyDecision($s, 'refused');
                        Notification::make()->title(__('admin.refused_ok'))->success()->send();
                    }),
                Tables\Actions\Action::make('preview')->label(__('admin.preview'))->icon('heroicon-o-arrow-top-right-on-square')
                    ->url(fn (Spa $s) => route('spa.show', ['locale' => 'fr', 'spa' => $s]), shouldOpenInNewTab: true)
                    ->visible(fn (Spa $s) => $s->status === 'published'),
                Tables\Actions\Action::make('manage')->label(__('admin.manage'))->icon('heroicon-o-wrench-screwdriver')
                    ->url(fn (Spa $s) => url("/partenaire/{$s->slug}")),
                Tables\Actions\EditAction::make(),
            ]);
    }

    public static function getRelations(): array
    {
        return [RelationManagers\ExtrasRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListSpas::route('/'),
            'edit' => Pages\EditSpa::route('/{record}/edit'),
        ];
    }
}
