<?php

namespace App\Filament\Partner\Pages;

use App\Domain\Billing\BillingGate;
use App\Models\ActivityLog;
use App\Models\Partner;
use App\Models\Spa;
use Filament\Facades\Filament;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

/** Informations légales de facturation du partenaire (entité juridique), communes à ses établissements. */
class BillingProfile extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-document-text';

    protected static ?int $navigationSort = 60;

    protected static string $view = 'filament.partner.pages.billing-profile';

    public ?array $data = [];

    public static function getNavigationGroup(): ?string
    {
        return __('billing.nav_group');
    }

    public static function getNavigationLabel(): string
    {
        return __('billing.profile_nav');
    }

    public function getTitle(): string
    {
        return __('billing.profile_title');
    }

    public function getSubheading(): ?string
    {
        return __('billing.profile_help');
    }

    /** Le partenaire est toujours résolu depuis le tenant courant, jamais depuis la requête. */
    private function partner(): Partner
    {
        /** @var Spa $spa */
        $spa = Filament::getTenant();

        return $spa->partner;
    }

    public function mount(): void
    {
        $this->form->fill($this->partner()->only(self::fields()));
    }

    /** @return list<string> */
    public static function fields(): array
    {
        return ['company_name', 'legal_form', 'tax_id', 'registry_number', 'ice', 'ice_not_applicable', 'ice_not_applicable_reason', 'billing_address', 'billing_city', 'billing_email'];
    }

    public function form(Form $form): Form
    {
        return $form->statePath('data')->schema(self::schema());
    }

    /** @return array<int, Forms\Components\Component> */
    public static function schema(bool $withCompany = true): array
    {
        return array_values(array_filter([
            $withCompany ? Forms\Components\TextInput::make('company_name')->label(__('partner.company_name'))->required()->maxLength(190) : null,
            Forms\Components\TextInput::make('legal_form')->label(__('admin.legal_form'))->maxLength(60),
            Forms\Components\TextInput::make('tax_id')->label(__('admin.tax_id'))->maxLength(60),
            Forms\Components\TextInput::make('registry_number')->label(__('admin.registry_number'))->maxLength(60),
            Forms\Components\TextInput::make('ice')->label(__('billing.ice'))->regex('/^\d{15}$/')->maxLength(15)
                ->disabled(fn (Forms\Get $get) => (bool) $get('ice_not_applicable'))->dehydrated(),
            Forms\Components\Toggle::make('ice_not_applicable')->label(__('billing.ice_not_applicable'))->live(),
            Forms\Components\TextInput::make('ice_not_applicable_reason')->label(__('billing.ice_not_applicable_reason'))->maxLength(190)
                ->visible(fn (Forms\Get $get) => (bool) $get('ice_not_applicable'))->required(fn (Forms\Get $get) => (bool) $get('ice_not_applicable')),
            Forms\Components\TextInput::make('billing_address')->label(__('billing.billing_address'))->maxLength(255)->columnSpanFull(),
            Forms\Components\TextInput::make('billing_city')->label(__('billing.billing_city'))->maxLength(90),
            Forms\Components\TextInput::make('billing_email')->label(__('billing.billing_email'))->email()->maxLength(190)->helperText(__('billing.billing_email_help')),
        ]));
    }

    /** @return list<string> */
    public function blockers(): array
    {
        return app(BillingGate::class)->partnerBlockers($this->partner()->fresh());
    }

    public function save(): void
    {
        $partner = $this->partner();
        $data = array_intersect_key($this->form->getState(), array_flip(self::fields()));
        if (! empty($data['ice_not_applicable'])) {
            $data['ice'] = null;
        } else {
            $data['ice_not_applicable_reason'] = null;
        }
        $old = $partner->only(array_keys($data));
        $partner->update($data);
        ActivityLog::record('partner.billing_profile_updated', $partner, ['changed' => array_keys(array_diff_assoc($data, $old))]);
        Notification::make()->title(__('billing.profile_saved'))->success()->send();
    }
}
