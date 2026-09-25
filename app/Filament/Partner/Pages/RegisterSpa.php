<?php

namespace App\Filament\Partner\Pages;

use App\Domain\Catalogue\Presentation;
use App\Domain\Catalogue\PublicationChecklist;
use App\Domain\Partner\OnboardingService;
use App\Filament\Forms\Components\PhoneField;
use App\Filament\Partner\Forms\SpaForm;
use App\Models\Amenity;
use App\Models\Category;
use App\Models\Spa;
use App\Models\SpaHour;
use App\Models\TreatmentStep;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Grid;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\TimePicker;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\Wizard;
use Filament\Forms\Components\Wizard\Step;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Notifications\Notification;
use Filament\Pages\Tenancy\RegisterTenant;
use Filament\Support\Enums\MaxWidth;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Js;
use Illuminate\Validation\ValidationException;

/**
 * Assistant « Référencer mon spa » en 7 étapes. Chaque étape validée est enregistrée immédiatement
 * (brouillon repris automatiquement à la prochaine visite) ; la dernière étape envoie la fiche pour validation.
 */
class RegisterSpa extends RegisterTenant
{
    protected static string $view = 'filament.partner.pages.register-spa';

    public ?int $spaId = null;

    public static function getLabel(): string
    {
        return __('partner.add_spa');
    }

    public function getMaxWidth(): MaxWidth|string|null
    {
        return MaxWidth::FourExtraLarge;
    }

    public function mount(): void
    {
        abort_unless(static::canView(), 404);

        $partner = $this->user()->partner;
        abort_unless($partner, 403);

        $draft = request()->boolean('new') ? null : $this->service()->currentDraft($partner, request()->integer('spa') ?: null);
        $this->spaId = $draft?->id;
        $this->form->fill($this->initialState($draft));
    }

    public function form(Form $form): Form
    {
        return $form->schema([
            Wizard::make([
                $this->stepAccount(),
                $this->stepSpa(),
                $this->stepPhotos(),
                $this->stepServices(),
                $this->stepTreatments(),
                $this->stepHours(),
                $this->stepSummary(),
            ])
                ->startOnStep(fn () => $this->startStep())
                ->skippable(false)
                ->nextAction(fn ($action) => $action->label(__('partner.wizard_next')))
                ->previousAction(fn ($action) => $action->label(__('partner.wizard_previous')))
                ->submitAction(new HtmlString(Blade::render(<<<'BLADE'
                    <x-filament::button type="submit" size="lg" color="success" icon="heroicon-o-paper-airplane">
                        {{ __('partner.submit_for_review') }}
                    </x-filament::button>
                BLADE))),
        ]);
    }

    /* ----------------------------------------------------------------- étapes */

    private function stepAccount(): Step
    {
        return Step::make(__('partner.step_account'))->description(__('partner.step_account_help'))->icon('heroicon-o-user')->schema([
            Grid::make(2)->schema([
                TextInput::make('first_name')->label(__('partner.first_name'))->required()->maxLength(100),
                TextInput::make('last_name')->label(__('partner.last_name'))->required()->maxLength(100),
            ]),
            TextInput::make('company_name')->label(__('partner.company_name'))->required()->maxLength(190),
            TextInput::make('account_email')->label('E-mail')->disabled()->dehydrated(false),
            PhoneField::make('phone', __('partner.phone'), required: true),
            Toggle::make('whatsapp_same')->label(__('partner.whatsapp_same'))->live(),
            PhoneField::make('whatsapp', __('partner.whatsapp'), required: true)->visible(fn (Get $get) => ! $get('whatsapp_same')),
        ])->afterValidation(function (Step $component) {
            $this->service()->saveAccount($this->user(), $component->getChildComponentContainer()->getState());
            $this->savedNotice();
        });
    }

    private function stepSpa(): Step
    {
        return Step::make(__('partner.step_spa'))->description(__('partner.step_spa_help'))->icon('heroicon-o-building-storefront')->schema([
            Grid::make(2)->schema([
                ...SpaForm::identity(),
                TextInput::make('address')->label(__('partner.address'))->required()->maxLength(255)->columnSpanFull()->helperText(__('partner.address_help')),
                SpaForm::map()->columnSpanFull(),
                Textarea::make('description_fr')->label(__('partner.description_fr'))->rows(4)->required()->minLength(40)->maxLength(4000)->helperText(__('partner.description_help')),
                Textarea::make('description_en')->label(__('partner.description_en'))->rows(4)->maxLength(4000),
                PhoneField::make('spa_phone', __('partner.spa_phone'), required: true)->columnSpanFull(),
                PhoneField::make('spa_whatsapp', __('partner.spa_whatsapp'))->columnSpanFull(),
                TextInput::make('email')->label(__('partner.spa_email'))->email()->maxLength(190),
                SpaForm::website(),
            ]),
            Placeholder::make('privacy')->label('')->content(__('partner.contact_privacy')),
            SpaForm::practical(),
        ])->afterValidation(function (Step $component) {
            $data = $component->getChildComponentContainer()->getState();
            $data['phone'] = $data['spa_phone'];
            $data['whatsapp'] = $data['spa_whatsapp'] ?? null;
            $spa = $this->service()->saveSpa($this->user()->partner, $this->spa(), $data);
            $this->spaId = $spa->id;
            $this->service()->markStep($spa, 2);
            $this->savedNotice();
        });
    }

    private function stepPhotos(): Step
    {
        $min = (int) config('hl.min_photos');
        $max = (int) config('hl.max_photos');

        return Step::make(__('partner.step_photos'))->description(__('partner.step_photos_help', ['min' => $min, 'max' => $max]))->icon('heroicon-o-photo')->schema([
            SpaForm::photoUpload('photos', multiple: true)->label(__('partner.section_photos'))
                ->minFiles($min)->maxFiles($max)->required()
                ->extraAlpineAttributes(['x-init' => "\$el.addEventListener('FilePond:warning', (e) => { if (e.detail?.error?.body === 'Max files') new FilamentNotification().title(".Js::from(__('partner.photos_max_error', ['max' => $max])).').danger().send() })'])
                ->validationMessages(['min' => __('partner.photos_min_error', ['min' => $min]), 'max' => __('partner.photos_max_error', ['max' => $max])]),
            Placeholder::make('photos_tip')->label('')->content(__('partner.photos_tip')),
        ])->afterValidation(function (Step $component) {
            $state = $component->getChildComponentContainer()->getState();
            $this->service()->savePhotos($this->requireSpa(), array_values($state['photos'] ?? []));
            $this->service()->markStep($this->requireSpa(), 3);
            $this->savedNotice();
        });
    }

    private function stepServices(): Step
    {
        return Step::make(__('partner.step_services'))->description(__('partner.step_services_help'))->icon('heroicon-o-sparkles')->schema([
            CheckboxList::make('categories')->label(__('partner.experiences'))->options(fn () => Category::options())->columns(2)->required()->helperText(__('partner.experiences_help')),
            CheckboxList::make('amenities')->label(__('partner.amenities'))->options(fn () => Amenity::options())->columns(2),
        ])->afterValidation(function (Step $component) {
            $state = $component->getChildComponentContainer()->getState();
            $this->service()->saveServices($this->requireSpa(), array_map('intval', $state['categories'] ?? []), array_map('intval', $state['amenities'] ?? []));
            $this->service()->markStep($this->requireSpa(), 4);
            $this->savedNotice();
        });
    }

    private function stepTreatments(): Step
    {
        return Step::make(__('partner.step_treatments'))->description(__('partner.step_treatments_help'))->icon('heroicon-o-currency-dollar')->schema([
            Repeater::make('treatments')->label('')->minItems(1)->defaultItems(1)->reorderable(false)
                ->itemLabel(fn (array $state) => $state['name_fr'] ?? null)
                ->addActionLabel(__('partner.add_treatment'))
                ->schema([
                    TextInput::make('name_fr')->label(__('partner.name_fr'))->required()->maxLength(190)->columnSpan(['default' => 1, 'lg' => 2]),
                    TextInput::make('name_en')->label(__('partner.name_en'))->maxLength(190)->columnSpan(['default' => 1, 'lg' => 2]),
                    Select::make('category')->label(__('partner.category'))->options(__('ui.cat'))->required()->default('hammam')->native(false)->live(),
                    TextInput::make('duration_min')->label(__('partner.duration'))->numeric()->minValue(15)->maxValue(480)->step(5)->suffix('min')->required(fn (Get $get) => $get('category') !== 'ritual')->default(60)
                        ->hidden(fn (Get $get) => $get('category') === 'ritual'),
                    Placeholder::make('total_duration')->label(__('partner.total_duration'))
                        ->visible(fn (Get $get) => $get('category') === 'ritual')
                        ->content(fn (Get $get) => Presentation::duration((int) collect($get('components') ?? [])->sum(fn ($c) => (int) ($c['duration_min'] ?? 0)))),
                    TextInput::make('price_solo')->label(__('partner.price_solo'))->numeric()->minValue(1)->suffix(config('hl.currency'))->required(),
                    TextInput::make('price_couple')->label(__('partner.price_couple'))->numeric()->minValue(1)->suffix(config('hl.currency'))->helperText(__('partner.price_couple_help')),
                    Repeater::make('components')->label(__('partner.components'))->helperText(__('partner.components_help'))
                        ->visible(fn (Get $get) => $get('category') === 'ritual')
                        ->minItems(fn (Get $get) => $get('category') === 'ritual' ? 2 : 0)->defaultItems(2)->reorderable()->live()
                        ->addActionLabel(__('partner.add_component'))->columnSpanFull()
                        ->schema([
                            Select::make('kind')->label(__('partner.component_kind'))->options(__('partner.component_kinds'))->required()->native(false)->default('hammam'),
                            TextInput::make('duration_min')->label(__('partner.duration'))->numeric()->minValue(5)->maxValue(480)->step(5)->suffix('min')->required()->default(45),
                            TextInput::make('label')->label(__('partner.component_label'))->maxLength(120)->helperText(__('partner.component_label_help')),
                        ])->columns(3),
                    Select::make('included')->label(__('partner.included'))->options(Presentation::includedOptions())->multiple()->native(false)->helperText(__('partner.included_help'))->columnSpan(['default' => 1, 'lg' => 2]),
                    Toggle::make('featured')->label(__('partner.featured'))->helperText(__('partner.featured_help'))->live()->inline(false),
                    Select::make('featured_badge')->label(__('partner.featured_badge'))->options(Presentation::badgeOptions())->default('signature')->native(false)
                        ->visible(fn (Get $get) => (bool) $get('featured')),
                    Textarea::make('description_fr')->label(__('partner.description_fr'))->rows(2)->maxLength(2000)->columnSpanFull(),
                ])->columns(4),
            Placeholder::make('treatments_tip')->label('')->content(__('partner.treatments_tip')),
        ])->afterValidation(function (Step $component) {
            $state = $component->getChildComponentContainer()->getState();
            $rows = array_values($state['treatments'] ?? []);
            if (collect($rows)->filter(fn ($r) => ! empty($r['featured']))->count() > 1) {
                throw ValidationException::withMessages(['data.treatments' => __('partner.featured_only_one')]);
            }
            $this->service()->saveTreatments($this->requireSpa(), $rows);
            $this->service()->markStep($this->requireSpa(), 5);
            $this->savedNotice();
        });
    }

    private function stepHours(): Step
    {
        $time = fn (string $name, string $label) => TimePicker::make($name)->label($label)->seconds(false)->required()
            ->formatStateUsing(fn ($state) => is_numeric($state) ? SpaHour::toHhmm((int) $state) : $state)
            ->dehydrateStateUsing(fn ($state) => SpaHour::toMinutes((string) $state));

        return Step::make(__('partner.step_hours'))->description(__('partner.step_hours_help'))->icon('heroicon-o-clock')->schema([
            Toggle::make('hours_every_day')->label(__('partner.hours_every_day'))->helperText(__('partner.hours_every_day_help'))->live()->default(true),
            Grid::make(2)->schema([
                $time('every_opens', __('partner.opens'))->default('10:00'),
                $time('every_closes', __('partner.closes'))->default('20:00'),
            ])->visible(fn (Get $get) => (bool) $get('hours_every_day')),
            Repeater::make('hours')->label(__('partner.section_hours'))->minItems(1)->reorderable(false)
                ->addActionLabel(__('partner.add_hours'))
                ->schema([
                    Select::make('weekday')->label(__('partner.weekday'))->options(SpaForm::weekdays())->required()->native(false),
                    $time('opens_min', __('partner.opens')),
                    $time('closes_min', __('partner.closes')),
                ])->columns(3)->visible(fn (Get $get) => ! $get('hours_every_day')),
            Grid::make(3)->schema([
                TextInput::make('hammam_capacity')->label(__('partner.hammam_capacity'))->numeric()->minValue(0)->maxValue(100)->default(0)->required()->helperText(__('partner.hammam_capacity_help')),
                TextInput::make('massage_cabins')->label(__('partner.massage_cabins'))->numeric()->minValue(0)->maxValue(50)->default(0)->required()->helperText(__('partner.massage_cabins_help')),
                TextInput::make('treatment_rooms')->label(__('partner.treatment_rooms'))->numeric()->minValue(0)->maxValue(50)->default(0)->required()->helperText(__('partner.treatment_rooms_help')),
            ]),
            Placeholder::make('hours_tip')->label('')->content(__('partner.hours_tip')),
        ])->afterValidation(function (Step $component) {
            $state = $component->getChildComponentContainer()->getState();
            if (((int) $state['hammam_capacity'] + (int) $state['massage_cabins'] + (int) $state['treatment_rooms']) < 1) {
                throw ValidationException::withMessages(['data.hammam_capacity' => __('partner.capacity_required')]);
            }
            if ($state['hours_every_day'] ?? false) {
                if ((int) $state['every_closes'] <= (int) $state['every_opens']) {
                    throw ValidationException::withMessages(['data.every_closes' => __('partner.hours_order_error')]);
                }
                $hours = array_map(fn (int $d) => ['weekday' => $d, 'opens_min' => $state['every_opens'], 'closes_min' => $state['every_closes']], range(0, 6));
            } else {
                foreach ($state['hours'] ?? [] as $i => $h) {
                    if ((int) $h['closes_min'] <= (int) $h['opens_min']) {
                        throw ValidationException::withMessages(["data.hours.$i.closes_min" => __('partner.hours_order_error')]);
                    }
                }
                $hours = array_values($state['hours'] ?? []);
            }
            $this->service()->saveHours($this->requireSpa(), $hours, $state);
            $this->service()->markStep($this->requireSpa(), 6);
            $this->savedNotice();
        });
    }

    private function stepSummary(): Step
    {
        return Step::make(__('partner.step_summary'))->description(__('partner.step_summary_help'))->icon('heroicon-o-clipboard-document-check')->schema([
            Placeholder::make('summary')->label('')->content(fn () => $this->summary()),
            Toggle::make('accept_terms')->label(__('partner.accept_terms'))->accepted()->validationMessages(['accepted' => __('partner.accept_terms_error')]),
        ]);
    }

    /* ---------------------------------------------------------- soumission */

    protected function handleRegistration(array $data): Model
    {
        $spa = $this->requireSpa();
        $missing = $this->missingBeforeSubmit($spa);
        if ($missing) {
            Notification::make()->title(__('partner.submit_blocked'))->body(implode(' · ', $missing))->danger()->persistent()->send();
            $this->halt();
        }

        $this->service()->submit($spa);
        Notification::make()->title(__('partner.submitted'))->body(__('partner.submitted_body'))->success()->persistent()->send();

        return $spa;
    }

    /** @return list<string> */
    private function missingBeforeSubmit(Spa $spa): array
    {
        $min = (int) config('hl.min_photos');
        $checks = [
            __('partner.miss_identity') => filled($spa->name) && filled($spa->city) && filled($spa->address) && filled($spa->phone) && filled($spa->description_fr),
            __('partner.miss_photos', ['min' => $min]) => $spa->photos()->count() >= $min,
            __('partner.miss_services') => $spa->categories()->exists(),
            __('partner.miss_treatments') => $spa->treatments()->where('status', 'active')->where('price_solo', '>', 0)->exists(),
            __('partner.miss_hours') => $spa->hours()->exists(),
            __('partner.miss_resources') => $spa->resources()->where('status', 'active')->exists(),
            __('partner.miss_step_resources') => ! TreatmentStep::whereIn('treatment_id', $spa->treatments()->where('status', 'active')->select('id'))
                ->whereDoesntHave('resourceType.resources', fn ($q) => $q->where('status', 'active'))->exists(),
        ];

        return array_keys(array_filter($checks, fn ($ok) => ! $ok));
    }

    /* ------------------------------------------------------------- helpers */

    private function summary(): HtmlString
    {
        $spa = $this->spa()?->load(['photos', 'categories', 'amenities', 'treatments', 'hours', 'cityRef']);
        if (! $spa) {
            return new HtmlString('<p class="text-sm text-gray-500">'.e(__('partner.summary_empty')).'</p>');
        }

        return new HtmlString(view('filament.partner.partials.spa-summary', [
            'spa' => $spa,
            'user' => $this->user()->fresh(),
            'capacity' => $this->service()->capacityOf($spa),
            'missing' => $this->missingBeforeSubmit($spa),
            'checklist' => PublicationChecklist::checks($spa),
            'weekdays' => SpaForm::weekdays(),
        ])->render());
    }

    /** @return array<string, mixed> */
    private function initialState(?Spa $spa): array
    {
        $user = $this->user();
        $state = [
            'first_name' => $user->first_name ?: (string) str($user->name)->before(' '),
            'last_name' => $user->last_name ?: (string) str($user->name)->after(' '),
            'company_name' => $user->partner?->company_name,
            'account_email' => $user->email,
            'phone' => $user->phone,
            'whatsapp_same' => ! $user->whatsapp || $user->whatsapp === $user->phone,
            'whatsapp' => $user->whatsapp !== $user->phone ? $user->whatsapp : null,
            'category' => 'hammam',
            'treatments' => [],
            'hours' => [],
            'hours_every_day' => true,
            'every_opens' => '10:00',
            'every_closes' => '20:00',
            'hammam_capacity' => 0,
            'massage_cabins' => 0,
            'treatment_rooms' => 0,
        ];
        if (! $spa) {
            return $state;
        }

        $spa->load(['photos', 'categories', 'amenities', 'treatments.steps.resourceType', 'hours']);

        return [
            'name' => $spa->name, 'category' => $spa->category, 'city_id' => $spa->city_id, 'area' => $spa->area, 'address' => $spa->address,
            'description_fr' => $spa->description_fr, 'description_en' => $spa->description_en,
            'spa_phone' => $spa->phone, 'spa_whatsapp' => $spa->whatsapp, 'email' => $spa->email, 'website' => $spa->website, 'location' => $spa->location,
            'photos' => $spa->photos->sortBy('sort_order')->pluck('path')->values()->all(),
            'categories' => $spa->categories->pluck('id')->all(),
            'amenities' => $spa->amenities->pluck('id')->all(),
            'treatments' => $spa->treatments->sortBy('sort_order')->map(fn ($t) => [
                'name_fr' => $t->name_fr, 'name_en' => $t->name_en, 'category' => $t->category, 'duration_min' => $t->duration_min,
                'price_solo' => $t->price_solo, 'price_couple' => $t->price_couple, 'description_fr' => $t->description_fr,
                'included' => $t->included ?? [], 'featured' => $t->featured_badge !== null, 'featured_badge' => $t->featured_badge ?? 'signature',
                'components' => $t->category === 'ritual' ? $t->steps->sortBy('position')->map(fn ($s) => [
                    'kind' => $s->resourceType?->slug ?? 'hammam', 'duration_min' => $s->duration_min, 'label' => $s->label,
                ])->values()->all() : [],
            ])->values()->all() ?: [],
            'practical_info' => $spa->practical_info ?? [],
            'hours' => $spa->hours->sortBy(['weekday', 'opens_min'])->map(fn ($h) => ['weekday' => $h->weekday, 'opens_min' => SpaHour::toHhmm($h->opens_min), 'closes_min' => SpaHour::toHhmm($h->closes_min)])->values()->all(),
            'hours_every_day' => $spa->hours->isEmpty() || $spa->hasSameHoursEveryDay(),
            'every_opens' => SpaHour::toHhmm($spa->hours->first()?->opens_min ?? 600),
            'every_closes' => SpaHour::toHhmm($spa->hours->first()?->closes_min ?? 1200),
        ] + $this->service()->capacityOf($spa) + $state;
    }

    private function startStep(): int
    {
        $spa = $this->spa();

        return $spa ? min(7, ($spa->onboarding_step ?? 2) + 1) : 1;
    }

    public function spa(): ?Spa
    {
        return $this->spaId ? $this->user()->partner?->spas()->whereKey($this->spaId)->first() : null;
    }

    /** @return Collection<int, Spa> */
    public function otherDrafts(): Collection
    {
        return $this->user()->partner?->spas()->whereNotNull('onboarding_step')->where('status', 'draft')
            ->where('id', '!=', $this->spaId ?? 0)->orderByDesc('updated_at')->get() ?? new Collection;
    }

    private function requireSpa(): Spa
    {
        $spa = $this->spa();
        if (! $spa) {
            throw ValidationException::withMessages(['data.name' => __('partner.spa_step_first')]);
        }

        return $spa;
    }

    private function user(): User
    {
        /** @var User $user */
        $user = Filament::auth()->user();

        return $user;
    }

    private function service(): OnboardingService
    {
        return app(OnboardingService::class);
    }

    private function savedNotice(): void
    {
        Notification::make()->title(__('partner.progress_saved'))->success()->duration(2500)->send();
    }
}
