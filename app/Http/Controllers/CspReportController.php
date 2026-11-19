<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;

/** Réception des rapports de violation CSP (navigateurs) → canal de log « csp ». */
class CspReportController extends Controller
{
    public function __invoke(Request $request): Response
    {
        // Au-delà de 30 rapports par minute et par adresse, le rapport est ignoré sans erreur : un navigateur bavard
        // ne doit ni remplir le journal ni faire bannir un visiteur légitime.
        if (! RateLimiter::attempt('csp-report:'.$request->ip(), 30, fn () => true, 60)) {
            return response()->noContent();
        }
        $raw = mb_substr((string) $request->getContent(), 0, 8000);
        $data = json_decode($raw, true);
        $report = is_array($data) ? ($data['csp-report'] ?? $data) : ['raw' => mb_substr($raw, 0, 500)];

        Log::channel('csp')->warning('csp-violation', [
            'ip' => $request->ip(),
            'ua' => mb_substr((string) $request->userAgent(), 0, 200),
            'report' => is_array($report) ? array_intersect_key($report, array_flip([
                'document-uri', 'blocked-uri', 'violated-directive', 'effective-directive', 'source-file', 'line-number', 'disposition',
            ])) : $report,
        ]);

        return response()->noContent();
    }
}
