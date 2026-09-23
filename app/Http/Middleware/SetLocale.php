<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\Response;

/**
 * Locale d'URL (/fr, /en). Rejouée par Livewire sur les requêtes de mise à jour (sans segment de route) :
 * la locale est alors relue en session.
 */
class SetLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        $locale = $request->route('locale') ?? $request->session()->get('hl.locale', config('app.locale'));
        if (! in_array($locale, config('hl.locales'), true)) {
            abort(404);
        }
        app()->setLocale($locale);
        URL::defaults(['locale' => $locale]);
        $request->session()->put('hl.locale', $locale);

        return $next($request);
    }
}
