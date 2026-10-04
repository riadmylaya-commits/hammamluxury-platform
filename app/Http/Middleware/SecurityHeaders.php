<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * En-têtes de sécurité HTTP (HSTS, CSP, Referrer-Policy, Permissions-Policy…).
 * La CSP est en mode « rapport » par défaut (config hl.csp_mode) : les violations
 * sont envoyées à /csp-report et journalisées sans bloquer la page.
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $h = $response->headers;

        $h->set('X-Content-Type-Options', 'nosniff');
        $h->set('X-Frame-Options', 'SAMEORIGIN');
        $h->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $h->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), payment=(), usb=(), interest-cohort=()');
        $h->set('Cross-Origin-Opener-Policy', 'same-origin');

        if ($request->isSecure() && config('hl.hsts', true)) {
            $h->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        $mode = config('hl.csp_mode', 'report');
        if ($mode !== 'off') {
            $name = $mode === 'enforce' ? 'Content-Security-Policy' : 'Content-Security-Policy-Report-Only';
            $h->set($name, self::policy());
        }

        return $response;
    }

    public static function policy(): string
    {
        $directives = [
            "default-src 'self'",
            "base-uri 'self'",
            "object-src 'none'",
            "frame-ancestors 'self'",
            "form-action 'self'",
            // Livewire/Alpine/Filament nécessitent inline + eval ; Leaflet via unpkg.
            "script-src 'self' 'unsafe-inline' 'unsafe-eval' https://unpkg.com",
            "style-src 'self' 'unsafe-inline' https://unpkg.com https://fonts.bunny.net",
            "font-src 'self' data: https://fonts.bunny.net",
            "img-src 'self' data: blob: https://unpkg.com https://tile.openstreetmap.org https://*.tile.openstreetmap.org https://picsum.photos https://fastly.picsum.photos",
            "connect-src 'self'",
            "worker-src 'self' blob:",
            'report-uri /csp-report',
        ];

        $extra = trim((string) config('hl.csp_extra', ''));

        return implode('; ', $directives).($extra !== '' ? '; '.$extra : '');
    }
}
