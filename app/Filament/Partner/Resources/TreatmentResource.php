<?php

namespace App\Filament\Partner\Resources;

use App\Domain\Catalogue\Presentation;
use App\Filament\Partner\Resources\TreatmentResource\Pages;
use App\Filament\Partner\Resources\TreatmentResource\RelationManagers\ExtrasRelationManager;
use App\Models\ResourceType;
use App\Models\Treatment;
use Filament\Facades\Filament;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class TreatmentResource extends Resource
{
    protected static ?string $model = Treatment::class;

    protected static ?string $navigationIcon = 'heroicon-o-sparkles';

    protected static ?int $navigationSort = 20;

    public static function getModelLabel(): string
    {
        return __('partner.treatment');
    }

    public static function getPluralModelLabel(): string
    {
        return __('partner.treatments');
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make(__('partner.section_treatment'))->schema([
                Forms\Components\TextInput::make('name_fr')->label(__('partner.name_fr'))->required()->maxLength(190)
                    ->live(onBlur: true)
                    ->afterStateUpdated(fn (Forms\Set $set, ?string $state, ?Model $record) => $record ?: $set('slug', Str::slug((string) $state))),
                Forms\Components\TextInput::make('name_en')->label(__('partner.name_en'))->maxLength(190),
                Forms\Components\Hidden::make('slug'),
                Forms\Components\Select::make('category')->label(__('partner.category'))->options(__('ui.cat'))->required()->default('hammam'),
                Forms\Components\Select::make('status')->label(__('partner.status'))->options(['active' => __('partner.active'), 'inactive' => __('partner.inactive')])->default('active')->required(),
                Forms\Components\Textarea::make('description_fr')->label(__('partner.description_fr'))->rows(3)->maxLength(2000),
                Forms\Components\Textarea::make('description_en')->label(__('partner.description_en'))->rows(3)->maxLength(2000),
            ])->columns(2),

            Forms\Components\Section::make(__('partner.included'))
                ->description(__('partner.included_help'))
                ->schema([
                    Forms\Components\CheckboxList::make('included')->label('')->options(Presentation::includedOptions())->columns(['default' => 2, 'lg' => 5]),
                    Forms\Components\Select::make('featured_badge')->label(__('partner.featured'))->options(Presentation::badgeOptions())->placeholder('—')->native(false)
                        ->helperText(fn (?Model $record) => self::featuredHelp($record)),
                ]),

            Forms\Components\Section::make(__('partner.section_prices'))
                ->description(__('partner.prices_help'))
                ->schema([
                    Forms\Components\TextInput::make('price_solo')->label(__('partner.price_solo'))->numeric()->minValue(0)->suffix(config('hl.currency'))->required(),
                    Forms\Components\TextInput::make('price_couple')->label(__('partner.price_couple'))->numeric()->minValue(0)->suffix(config('hl.currency'))->helperText(__('partner.price_couple_help')),
                    Forms\Components\TextInput::make('price_group')->label(__('partner.price_group'))->numeric()->minValue(0)->suffix(config('hl.currency'))->helperText(__('partner.price_group_help')),
                    Forms\Components\TextInput::make('party_min')->label(__('partner.party_min'))->numeric()->minValue(1)->default(1)->required(),
                    Forms\Components\TextInput::make('party_max')->label(__('partner.party_max'))->numeric()->minValue(1)->maxValue(config('hl.max_participants'))->default(10)->required(),
                ])->columns(3),

            Forms\Components\Section::make(__('partner.section_steps'))
                ->description(__('partner.steps_help'))
                ->schema([
                    Forms\Components\Repeater::make('steps')
                        ->relationship()
                        ->label('')
                        ->orderColumn('position')
                        ->reorderable()
                        ->minItems(1)
                        ->schema([
                            Forms\Components\Select::make('resource_type_id')->label(__('partner.resource_type'))
                                ->options(fn () => ResourceType::where('spa_id', Filament::getTenant()->id)->orderBy('sort_order')->pluck('name_fr', 'id'))
                                ->required(),
                            Forms\Components\TextInput::make('duration_min')->label(__('partner.duration'))->numeric()->minValue(5)->step(5)->suffix('min')->required()->default(60),
                            Forms\Components\TextInput::make('label')->label(__('partner.step_label'))->maxLength(120)->placeholder(__('partner.component_label_help')),
                        ])
                        ->columns(3)
                        ->addActionLabel(__('partner.add_step'))
                        ->defaultItems(1),
                ]),
        ]);
    }

    private static function featuredHelp(?Model $record): string
    {
        $other = Treatment::where('spa_id', Filament::getTenant()->id)->whereNotNull('featured_badge')
            ->when($record, fn ($q) => $q->whereKeyNot($record->getKey()))->first();

        return $other ? __('partner.featured_replaces').' ('.$other->name_fr.')' : __('partner.featured_help');
    }

    public static function table(Table $table): Table
    {
        return $table
            ->reorderable('sort_order')
            ->defaultSort('sort_order')
            ->columns([
                Tables\Columns\TextColumn::make('name_fr')->label(__('partner.treatment'))->searchable()->weight('bold')
                    ->icon(fn (Treatment $t) => $t->featured_badge ? 'heroicon-s-star' : null)->iconColor('warning')
                    ->description(fn (Treatment $t) => $t->steps->count() > 1 ? __('ui.package').' · '.$t->steps->map(fn ($s) => $s->duration_min.' min')->implode(' → ') : null),
                Tables\Columns\TextColumn::make('category')->label(__('partner.category'))->formatStateUsing(fn ($state) => __('ui.cat')[$state] ?? $state)->badge()->color('gray'),
                Tables\Columns\TextColumn::make('duration_min')->label(__('partner.duration'))->suffix(' min'),
                Tables\Columns\TextColumn::make('price_solo')->label(__('partner.price_solo'))->money(config('hl.currency'), locale: 'fr'),
                Tables\Columns\TextColumn::make('price_couple')->label(__('partner.price_couple'))->money(config('hl.currency'), locale: 'fr')->placeholder('—'),
                Tables\Columns\TextColumn::make('extras_count')->counts('extras')->label(__('partner.extras')),
                Tables\Columns\IconColumn::make('status')->label(__('partner.status'))->boolean(fn ($state) => $state === 'active')->getStateUsing(fn (Treatment $t) => $t->status === 'active'),
            ])
            ->actions([Tables\Actions\EditAction::make()])
            ->emptyStateHeading(__('partner.no_treatments'))
            ->emptyStateDescription(__('partner.no_treatments_help'));
    }

    public static function getRelations(): array
    {
        return [ExtrasRelationManager::class];
    }

    /** Les étapes saisies par le partenaire s'enchaînent : offsets cumulés, durée totale dérivée des étapes persistées. */
    public static function syncDuration(Treatment $t): void
    {
        $offset = 0;
        foreach ($t->steps()->orderBy('position')->orderBy('id')->get() as $i => $step) {
            $step->update(['offset_min' => $offset, 'position' => $i]);
            $offset += (int) $step->duration_min;
        }
        $t->load('steps');
        $t->update(['duration_min' => $t->computedDuration() ?: 60]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListTreatments::route('/'),
            'create' => Pages\CreateTreatment::route('/create'),
            'edit' => Pages\EditTreatment::route('/{record}/edit'),
        ];
    }
}
