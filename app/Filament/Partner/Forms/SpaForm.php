<?php

namespace App\Filament\Partner\Forms;

use App\Domain\Catalogue\Presentation;
use App\Domain\Geo\WebsiteUrl;
use App\Domain\Media\PhotoProcessor;
use App\Filament\Forms\Components\MapPicker;
use App\Filament\Forms\Components\PhoneField;
use App\Models\Amenity;
use App\Models\Category;
use App\Models\City;
use App\Models\SpaHour;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\TimePicker;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Set;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\HtmlString;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

class SpaForm
{
    /** @return array<string, string> slug => libellé (catégorie principale) */
    public static function categories(): array
    {
        return Category::query()->active()->get()->mapWithKeys(fn (Category $c) => [$c->slug => $c->label()])->all();
    }

    /** Upload photo sécurisé : types réels, 12 Mo max, 800×600 min, ré-encodage JPEG 1600 px côté serveur (PhotoProcessor). */
    public static function photoUpload(string $name, bool $multiple = false): FileUpload
    {
        $upload = FileUpload::make($name)->label('')->image()->disk('public')->directory('spas')
            ->acceptedFileTypes(PhotoProcessor::MIMES)->maxSize((int) (PhotoProcessor::MAX_BYTES / 1024))
            ->rules(PhotoProcessor::rules())
            ->helperText(__('partner.photo_rules', ['w' => PhotoProcessor::MIN_WIDTH, 'h' => PhotoProcessor::MIN_HEIGHT, 'mb' => (int) (PhotoProcessor::MAX_BYTES / 1024 / 1024)]))
            ->saveUploadedFileUsing(fn (TemporaryUploadedFile $file) => PhotoProcessor::store($file));

        return $multiple ? $upload->multiple()->reorderable()->appendFiles()->panelLayout('grid') : $upload;
    }

    public static function citySelect(): Select
    {
        return Select::make('city_id')->label(__('partner.city'))->options(fn () => City::options())->searchable()->preload()->required()->native(false);
    }

    /** Expériences proposées + équipements (référentiels admin), enregistrés via les relations many-to-many. */
    public static function services(): array
    {
        return [
            CheckboxList::make('categories')->label(__('partner.experiences'))->relationship('categories', 'name_fr', fn (Builder $query) => $query->active())
                ->getOptionLabelFromRecordUsing(fn (Category $c) => $c->label())->columns(2)->helperText(__('partner.experiences_help')),
            CheckboxList::make('amenities')->label(__('partner.amenities'))->relationship('amenities', 'name_fr', fn (Builder $query) => $query->active())
                ->getOptionLabelFromRecordUsing(fn (Amenity $a) => $a->label())->columns(2),
        ];
    }

    /** Champs minimaux pour créer un établissement. */
    public static function map(): MapPicker
    {
        return MapPicker::make('location')->label(__('partner.map'))->helperText(__('partner.map_help'));
    }

    /** Site web saisi librement (avec ou sans https://), normalisé en URL complète à l'enregistrement. */
    public static function website(): TextInput
    {
        return TextInput::make('website')->label(__('partner.website'))->maxLength(190)->placeholder('www.mon-spa.com')
            ->rule(fn () => WebsiteUrl::rule())->dehydrateStateUsing(fn (?string $state) => WebsiteUrl::normalize($state))
            ->live(onBlur: true)->afterStateUpdated(fn (Set $set, ?string $state) => $set('website', WebsiteUrl::normalize($state) ?? $state));
    }

    public static function identity(): array
    {
        return [
            TextInput::make('name')->label(__('partner.spa_name'))->required()->maxLength(190),
            Select::make('category')->label(__('partner.category'))->options(self::categories())->default('hammam')->required(),
            self::citySelect(),
            TextInput::make('area')->label(__('partner.area'))->maxLength(120)->helperText(__('partner.area_help')),
        ];
    }

    /** Infos pratiques (FAQ du spa), toutes facultatives, stockées dans `spas.practical_info`. */
    public static function practical(bool $collapsed = true): Section
    {
        return Section::make(__('partner.practical_info'))
            ->description(__('partner.practical_info_help'))
            ->collapsible()->collapsed($collapsed)
            ->statePath('practical_info')
            ->schema([
                Select::make('gender')->label(__('ui.practical.gender'))->options(Presentation::genderOptions())->native(false)->placeholder('—'),
                Select::make('children')->label(__('ui.practical.children'))->options(Presentation::yesNoAskOptions())->native(false)->placeholder('—'),
                Select::make('pregnant')->label(__('ui.practical.pregnant'))->options(Presentation::yesNoAskOptions())->native(false)->placeholder('—'),
                Select::make('accessible')->label(__('ui.practical.accessible'))->options(Presentation::yesNoAskOptions())->native(false)->placeholder('—'),
                CheckboxList::make('languages')->label(__('ui.practical.languages'))->options(Presentation::languageOptions())->columns(3)->columnSpanFull(),
                TextInput::make('bring')->label(__('ui.practical.bring'))->maxLength(190)->placeholder(__('partner.practical_bring_placeholder')),
                TextInput::make('notes')->label(__('ui.practical.notes'))->maxLength(190),
            ])->columns(2);
    }

    public static function full(): array
    {
        return [
            Section::make(__('partner.section_identity'))->schema([
                Placeholder::make('status_info')
                    ->label(__('partner.status'))
                    ->content(fn ($record) => new HtmlString('<strong>'.__('partner.spa_status_'.$record->status).'</strong>'
                        .($record->status_note ? '<br><span class="text-sm text-gray-500">'.e($record->status_note).'</span>' : ''))),
                ...self::identity(),
                Textarea::make('description_fr')->label(__('partner.description_fr'))->rows(4)->maxLength(4000),
                Textarea::make('description_en')->label(__('partner.description_en'))->rows(4)->maxLength(4000),
            ])->columns(2),

            Section::make(__('partner.section_services'))->schema(self::services())->columns(2),

            self::practical(),

            Section::make(__('partner.section_photos'))
                ->description(__('partner.photos_help', ['min' => config('hl.min_photos')]))
                ->schema([
                    Repeater::make('photos')
                        ->relationship()
                        ->label('')
                        ->orderColumn('sort_order')
                        ->grid(3)
                        ->schema([
                            self::photoUpload('path')->required(),
                            TextInput::make('caption_fr')->label(__('partner.caption'))->maxLength(190),
                            Toggle::make('is_cover')->label(__('partner.is_cover')),
                        ])
                        ->addActionLabel(__('partner.add_photo'))
                        ->defaultItems(0),
                ]),

            Section::make(__('partner.section_address'))->schema([
                TextInput::make('address')->label(__('partner.address'))->maxLength(255)->columnSpanFull()->helperText(__('partner.address_help')),
                self::map()->columnSpanFull(),
                PhoneField::make('phone', __('partner.phone'))->columnSpanFull(),
                PhoneField::make('whatsapp', __('partner.whatsapp'))->columnSpanFull(),
                TextInput::make('email')->label('E-mail')->email()->maxLength(190),
                self::website(),
                Placeholder::make('privacy')->label('')->content(__('partner.contact_privacy'))->columnSpanFull(),
            ])->columns(3),

            Section::make(__('partner.section_hours'))
                ->description(__('partner.hours_help'))
                ->schema([
                    Repeater::make('hours')
                        ->relationship()
                        ->label('')
                        ->schema([
                            Select::make('weekday')->label(__('partner.weekday'))->options(self::weekdays())->required(),
                            TimePicker::make('opens_min')->label(__('partner.opens'))->seconds(false)->required()
                                ->formatStateUsing(fn ($state) => $state === null ? null : SpaHour::toHhmm((int) $state))
                                ->dehydrateStateUsing(fn ($state) => SpaHour::toMinutes((string) $state)),
                            TimePicker::make('closes_min')->label(__('partner.closes'))->seconds(false)->required()
                                ->formatStateUsing(fn ($state) => $state === null ? null : SpaHour::toHhmm((int) $state))
                                ->dehydrateStateUsing(fn ($state) => SpaHour::toMinutes((string) $state)),
                        ])
                        ->columns(3)
                        ->addActionLabel(__('partner.add_hours'))
                        ->defaultItems(0),
                ]),

            Section::make(__('partner.section_rules'))->schema([
                Select::make('slot_step_minutes')->label(__('partner.slot_step'))->options([15 => '15 min', 30 => '30 min', 60 => '60 min'])->placeholder(__('partner.default_value', ['v' => config('hl.slot_step_minutes').' min'])),
                TextInput::make('min_lead_minutes')->label(__('partner.min_lead'))->numeric()->minValue(0)->suffix('min')->placeholder((string) config('hl.min_lead_minutes')),
                TextInput::make('cancellation_hours')->label(__('partner.cancellation_hours'))->numeric()->minValue(0)->suffix('h')->default(24),
            ])->columns(3),
        ];
    }

    public static function weekdays(): array
    {
        return __('ui.days');
    }
}
