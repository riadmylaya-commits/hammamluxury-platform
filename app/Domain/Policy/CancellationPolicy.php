<?php

namespace App\Domain\Policy;

use Carbon\CarbonInterface;

/**
 * Politique d'annulation : liste fermée de délais proposée par HammamLuxury, choisie par le partenaire
 * (défaut établissement, surcharge par prestation). Avant la limite : gratuit ; après : 100 % du montant dû.
 * Tarif non remboursable : réduction d'au moins MIN_NR_DISCOUNT % sur le tarif standard, 100 % dû en cas d'annulation.
 */
class CancellationPolicy
{
    public const HOURS = [8, 24, 48, 72, 168, 360, 504];

    public const DEFAULT_HOURS = 24;

    public const MIN_NR_DISCOUNT = 10;

    public const MAX_NR_DISCOUNT = 90;

    public const RATES = ['standard', 'non_refundable'];

    /** Le tarif non remboursable est-il proposé sur la plateforme ? */
    public static function nrEnabled(): bool
    {
        return (bool) config('hl.features.non_refundable');
    }

    public static function isValidHours(mixed $h): bool
    {
        return in_array((int) $h, self::HOURS, true);
    }

    public static function nearest(int $h): int
    {
        $best = self::DEFAULT_HOURS;
        foreach (self::HOURS as $opt) {
            if (abs($opt - $h) < abs($best - $h)) {
                $best = $opt;
            }
        }

        return $best;
    }

    public static function isValidDiscount(mixed $pct): bool
    {
        return $pct !== null && (int) $pct >= self::MIN_NR_DISCOUNT && (int) $pct <= self::MAX_NR_DISCOUNT;
    }

    public static function discounted(float $price, int $pct): float
    {
        return round($price * (100 - $pct) / 100, 2);
    }

    /** Libellé court d'un délai (« 48 h », « 7 jours », « 8 h — dernière minute »). */
    public static function label(?int $hours): string
    {
        if ($hours === null) {
            return __('ui.policy.to_confirm_label');
        }

        return __('ui.policy.hours.'.$hours) ?: $hours.' h';
    }

    /** @return array<int, string> options délai → libellé */
    public static function options(): array
    {
        $out = [];
        foreach (self::HOURS as $h) {
            $out[$h] = self::label($h);
        }

        return $out;
    }

    public static function deadline(CarbonInterface $start, int $hours): CarbonInterface
    {
        return $start->copy()->subHours($hours);
    }

    /**
     * Phrase sur la date limite d'annulation gratuite, identique sur le site et dans les e-mails : la date tant qu'elle
     * est à venir, sinon le rappel que le délai est dépassé (jamais « gratuite jusqu'au » suivi d'une date passée).
     */
    public static function deadlineNotice(CarbonInterface $until, string $formattedDate, string $amount, ?CarbonInterface $now = null, bool $pending = false): string
    {
        $open = ($now ?? now())->lt($until);
        if ($pending) {
            // Demande pas encore confirmée : aucuns frais ; les conditions s'appliqueront une fois la réservation confirmée.
            return __('ui.policy.pending_free').' '.($open
                ? __('ui.policy.free_until', ['date' => $formattedDate])
                : __('ui.policy.late_once_confirmed', ['amount' => $amount]));
        }

        return $open
            ? __('ui.policy.free_until', ['date' => $formattedDate])
            : __('ui.policy.late_now', ['amount' => $amount]);
    }
}
