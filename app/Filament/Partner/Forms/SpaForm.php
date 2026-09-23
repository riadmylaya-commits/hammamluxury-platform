<?php

namespace App\Filament\Partner\Forms;

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
use Illuminate\Support\HtmlString;

class SpaForm
{
    public static function categories(): array
    {
        return __('ui.spa_cat');
    }

    public static function features(): array
    {
        return __('ui.features');
    }

    /** Champs minimaux pour créer un établissement. */
    public static function identity(): array
    {
        return [
            TextInput::make('name')->label(__('partner.spa_name'))->required()->maxLength(190),
            Select::make('category')->label(__('partner.category'))->options(self::categories())->default('hammam')->required(),
            TextInput::make('city')->label(__('partner.city'))->required()->maxLength(90),
            TextInput::make('area')->label(__('partner.area'))->maxLength(120)->helperText(__('partner.area_help')),
        ];
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
                CheckboxList::make('features')->label(__('partner.features'))->options(self::features())->columns(2),
            ])->columns(2),

            Section::make(__('partner.section_photos'))
                ->description(__('partner.photos_help', ['min' => config('hl.min_photos')]))
                ->schema([
                    Repeater::make('photos')
                        ->relationship()
                        ->label('')
                        ->orderColumn('sort_order')
                        ->grid(3)
                        ->schema([
                            FileUpload::make('path')->label('')->image()->disk('public')->directory('spas')
                                ->imageResizeMode('cover')->imageResizeTargetWidth(1600)->imageResizeTargetHeight(1067)->maxSize(6144)->required(),
                            TextInput::make('caption_fr')->label(__('partner.caption'))->maxLength(190),
                            Toggle::make('is_cover')->label(__('partner.is_cover')),
                        ])
                        ->addActionLabel(__('partner.add_photo'))
                        ->defaultItems(0),
                ]),

            Section::make(__('partner.section_address'))->schema([
                TextInput::make('address')->label(__('partner.address'))->maxLength(255)->columnSpanFull(),
                TextInput::make('phone')->label(__('partner.phone'))->tel()->maxLength(40),
                TextInput::make('email')->label('E-mail')->email()->maxLength(190),
                TextInput::make('website')->label(__('partner.website'))->url()->maxLength(190),
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
