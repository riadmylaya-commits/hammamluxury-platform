<?php

namespace App\Http\Middleware;

use App\Domain\Security\TwoFactor;
use App\Models\User;
use Closure;
use Filament\Facades\Filament;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Espaces Admin / Partenaire : un compte avec 2FA active doit saisir son code à chaque session ;
 * un administrateur sans 2FA est conduit à l'activer avant toute autre page.
 */
class RequireTwoFactor
{
    public function __construct(private TwoFactor $twoFactor) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (! $user instanceof User) {
            return $next($request);
        }

        $route = $request->route()?->getName() ?? '';
        if (str_contains($route, '.two-factor.') || str_ends_with($route, '.auth.logout')) {
            return $next($request);
        }

        $livewire = $route === 'livewire.update' || $request->is('livewire/update');
        if ($livewire && $this->onlyTwoFactorComponents($request)) {
            return $next($request);
        }

        $panel = Filament::getCurrentPanel()?->getId() ?? 'admin';

        if ($user->hasTwoFactor() && ! $this->twoFactor->hasPassed($user)) {
            abort_if($livewire, 403);
            session(['url.intended' => $request->fullUrl()]);

            return redirect()->route("filament.{$panel}.two-factor.challenge");
        }

        if ($user->mustEnableTwoFactor() && ! $user->hasTwoFactor()) {
            abort_if($livewire, 403);

            return redirect()->route("filament.{$panel}.two-factor.setup");
        }

        return $next($request);
    }

    /** Une requête Livewire ne concernant que les pages 2FA (activation / vérification) doit passer. */
    private function onlyTwoFactorComponents(Request $request): bool
    {
        $components = $request->input('components', []);
        if (! is_array($components) || $components === []) {
            return false;
        }

        foreach ($components as $component) {
            $snapshot = json_decode((string) ($component['snapshot'] ?? ''), true);
            $name = (string) ($snapshot['memo']['name'] ?? '');
            if (! str_contains($name, 'two-factor')) {
                return false;
            }
        }

        return true;
    }
}
