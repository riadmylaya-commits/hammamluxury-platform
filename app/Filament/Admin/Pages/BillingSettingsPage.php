<?php

namespace App\Filament\Admin\Pages;

use App\Domain\Billing\BillingGate;
use App\Domain\Billing\BillingLockedException;
use App\Domain\Billing\BillingSettings;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use InvalidArgumentException;

/** Paramètres de facturation (lot F0) : verrous visibles, fiscalité en attente de validation comptable, émetteur. */
class BillingSettingsPage extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-banknotes';

    protected static ?int $navigationSort = 60;

    protected static string $view = 'filament.admin.pages.billing-settings';

    public ?array $data = [];

    public static function getNavigationGroup(): ?string
    {
        return __('billing.nav_group');
    }

    public static function getNavigationLabel(): string
    {
        return __('billing.settings_nav');
    }

    public function getTitle(): string
    {
        return __('billing.settings_title');
    }

    public function getSubheading(): ?string
    {
        return __('billing.settings_help');
    }

    public function mount(BillingSettings $settings): void
    {
        $state = [];
        foreach (BillingSettings::KEYS as $key) {
            $value = $settings->get($key);
            $state[str_replace('.', '__', $key)] = is_bool($value) ? $value : ($value === null ? null : (string) $value);
        }
        $this->form->fill($state);
    }

    public function form(Form $form): Form
    {
        return $form->statePath('data')->schema([
            Forms\Components\Section::make(__('billing.section_activation'))->schema([
                Forms\Components\Toggle::make('enabled')->label(__('billing.enabled'))->helperText(__('billing.enabled_help')),
                Forms\Components\TextInput::make('start_period')->label(__('billing.start_period'))->helperText(__('billing.start_period_help'))
                    ->placeholder('2027-01')->regex('/^\d{4}-(0[1-9]|1[0-2])$/')->maxLength(7),
            ])->columns(2),
            Forms\Components\Section::make(__('billing.section_tax'))->schema([
                Forms\Components\Select::make('vat_mode')->label(__('billing.vat_mode'))->required()->live()
                    ->options(collect(BillingSettings::VAT_MODES)->mapWithKeys(fn ($m) => [$m => __('billing.vat_'.$m)])),
                Forms\Components\TextInput::make('vat_rate')->label(__('billing.vat_rate'))->numeric()->minValue(0)->maxValue(100)->suffix('%')
                    ->visible(fn (Forms\Get $get) => $get('vat_mode') !== 'none'),
                Forms\Components\DatePicker::make('accountant_validated_at')->label(__('billing.accountant_validated_at'))->helperText(__('billing.accountant_help'))->native(false)->maxDate(now()),
            ])->columns(3),
            Forms\Components\Section::make(__('billing.section_issuer'))->schema([
                Forms\Components\TextInput::make('issuer__company_name')->label(__('billing.issuer_company_name'))->maxLength(190),
                Forms\Components\TextInput::make('issuer__legal_form')->label(__('billing.issuer_legal_form'))->maxLength(60),
                Forms\Components\TextInput::make('issuer__ice')->label(__('billing.issuer_ice'))->regex('/^\d{15}$/')->maxLength(15),
                Forms\Components\TextInput::make('issuer__tax_id')->label(__('billing.issuer_tax_id'))->maxLength(60),
                Forms\Components\TextInput::make('issuer__registry_number')->label(__('billing.issuer_registry_number'))->maxLength(60),
                Forms\Components\TextInput::make('issuer__bank_account')->label(__('billing.issuer_bank_account'))->maxLength(190),
                Forms\Components\Textarea::make('issuer__address')->label(__('billing.issuer_address'))->rows(2)->maxLength(255)->columnSpanFull(),
            ])->columns(2),
        ]);
    }

    /** @return list<string> */
    public function blockers(): array
    {
        return app(BillingGate::class)->globalBlockers();
    }

    public function save(BillingSettings $settings): void
    {
        $data = $this->form->getState();
        try {
            // `enabled` en dernier : son activation exige que tous les autres verrous soient déjà levés.
            foreach (BillingSettings::KEYS as $key) {
                if ($key === 'enabled') {
                    continue;
                }
                $settings->set($key, $data[str_replace('.', '__', $key)] ?? null);
            }
            $settings->set('enabled', (bool) ($data['enabled'] ?? false));
        } catch (BillingLockedException $e) {
            $this->data['enabled'] = false;
            Notification::make()->title(__('billing.locked'))->body(implode(' · ', array_map(fn ($b) => __('billing.blocker.'.$b), $e->blockers)))->danger()->send();

            return;
        } catch (InvalidArgumentException $e) {
            Notification::make()->title($e->getMessage())->danger()->send();

            return;
        }
        Notification::make()->title(__('billing.saved'))->success()->send();
    }
}
