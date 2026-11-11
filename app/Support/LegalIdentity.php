<?php

namespace App\Support;

/**
 * Identité juridique de l'éditeur pour les pages légales : valeurs du .env (config hl.legal) échappées, ou repère
 * « [à compléter] » tant que l'information manque. Les champs facultatifs (capital, patente, téléphone, CNDP) restent
 * vides s'ils ne s'appliquent pas.
 */
final class LegalIdentity
{
    public const REQUIRED = ['company_name', 'legal_form', 'rc_city', 'rc_number', 'ice', 'tax_id', 'address', 'publication_director', 'email'];

    public const OPTIONAL = ['capital', 'patente', 'phone', 'cndp'];

    private const LABELS = [
        'company_name' => ['dénomination sociale', 'company name'],
        'legal_form' => ['forme juridique', 'legal form'],
        'rc_city' => ['ville du RC', 'trade register city'],
        'rc_number' => ['n° RC', 'RC no.'],
        'ice' => ['n° ICE', 'ICE no.'],
        'tax_id' => ['identifiant fiscal', 'tax ID'],
        'address' => ['adresse du siège', 'registered office address'],
        'publication_director' => ['directeur de la publication', 'publication director'],
        'email' => ['e-mail de contact', 'contact e-mail'],
        'court_city' => ['ville du siège', 'city of the registered office'],
    ];

    /** @return array<string, mixed> */
    public static function values(): array
    {
        $cfg = (array) config('hl.legal', []);
        $en = app()->getLocale() === 'en';
        $out = ['incomplete' => false];

        foreach (self::REQUIRED as $key) {
            $value = trim((string) ($cfg[$key] ?? ''));
            if ($value === '') {
                $out['incomplete'] = true;
                $value = '<em>['.self::LABELS[$key][$en ? 1 : 0].($en ? ' to be completed' : ' à compléter').']</em>';
            } else {
                $value = e($value);
            }
            $out[$key] = $value;
        }

        foreach (self::OPTIONAL as $key) {
            $value = trim((string) ($cfg[$key] ?? ''));
            $out[$key] = $value === '' ? '' : e($value);
        }

        $court = trim((string) ($cfg['court_city'] ?? ''));
        $out['court_city'] = $court !== '' ? e($court) : $out['rc_city'];

        $years = max(1, (int) ($cfg['retention_years'] ?? 5));
        $out['retention'] = $years.' ans à compter de la date de la prestation';
        $out['retention_en'] = $years.' years from the date of the service';

        return $out;
    }
}
