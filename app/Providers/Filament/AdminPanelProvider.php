<?php

namespace App\Providers\Filament;

use App\Filament\Shared\Pages\TwoFactorChallenge;
use App\Filament\Shared\Pages\TwoFactorSetup;
use App\Http\Middleware\RequireTwoFactor;
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
use Illuminate\Support\Facades\Route;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->id('admin')
            ->path('admin')
            ->login()
            ->passwordReset()
            ->brandName('HammamLuxury · Administration')
            ->colors(['primary' => Color::hex('#c4753b')])
            ->font('Inter')
            ->sidebarCollapsibleOnDesktop()
            ->discoverResources(in: app_path('Filament/Admin/Resources'), for: 'App\\Filament\\Admin\\Resources')
            ->discoverPages(in: app_path('Filament/Admin/Pages'), for: 'App\\Filament\\Admin\\Pages')
            ->pages([Pages\Dashboard::class])
            ->discoverWidgets(in: app_path('Filament/Admin/Widgets'), for: 'App\\Filament\\Admin\\Widgets')
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
            ->authenticatedRoutes(function () {
                Route::get('/securite', TwoFactorSetup::class)->name('two-factor.setup');
                Route::get('/securite/verification', TwoFactorChallenge::class)->name('two-factor.challenge');
            })
            ->userMenuItems([
                MenuItem::make()->label(fn () => __('security.menu'))->icon('heroicon-o-shield-check')
                    ->url(fn () => route('filament.'.filament()->getId().'.two-factor.setup')),
            ])
            ->livewireComponents([TwoFactorSetup::class, TwoFactorChallenge::class])
            ->authMiddleware([Authenticate::class, RequireTwoFactor::class]);
    }
}
