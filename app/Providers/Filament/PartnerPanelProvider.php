<?php

namespace App\Providers\Filament;

use App\Filament\Partner\Pages\EditSpaProfile;
use App\Filament\Partner\Pages\Register as RegisterPartner;
use App\Filament\Partner\Pages\RegisterSpa;
use App\Models\Spa;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\MenuItem;
use Filament\Pages;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

/** Espace partenaire : un compte, un ou plusieurs établissements (tenant = Spa). */
class PartnerPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->id('partner')
            ->path('partenaire')
            ->login()
            ->registration(RegisterPartner::class)
            ->passwordReset()
            ->brandName('HammamLuxury · Partenaires')
            ->colors(['primary' => Color::hex('#1f4d3f')])
            ->font('Inter')
            ->sidebarCollapsibleOnDesktop()
            ->tenant(Spa::class, slugAttribute: 'slug')
            ->tenantRegistration(RegisterSpa::class)
            ->tenantProfile(EditSpaProfile::class)
            ->tenantMenuItems([
                'profile' => MenuItem::make()->label(fn () => __('partner.spa_profile')),
                'register' => MenuItem::make()->label(fn () => __('partner.add_spa')),
            ])
            ->discoverResources(in: app_path('Filament/Partner/Resources'), for: 'App\\Filament\\Partner\\Resources')
            ->discoverPages(in: app_path('Filament/Partner/Pages'), for: 'App\\Filament\\Partner\\Pages')
            ->pages([Pages\Dashboard::class])
            ->discoverWidgets(in: app_path('Filament/Partner/Widgets'), for: 'App\\Filament\\Partner\\Widgets')
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                VerifyCsrfToken::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([Authenticate::class]);
    }
}
