<?php

namespace App\Domain\Catalogue;

use App\Models\Spa;

/**
 * « Configuration complète » : conditions pour que la disponibilité calculée reflète la capacité réelle.
 * Préalable à la réservation instantanée (activable par l'Admin seulement) ; revérifiée à chaque réservation.
 */
class CapacityReadiness
{
    /** @return array<string, bool> libellé => satisfait */
    public static function checks(Spa $spa): array
    {
        $treatments = $spa->treatments()->where('status', 'active');
        $resources = $spa->resources()->where('status', 'active');
        $maxStaff = (int) $spa->treatments()->where('status', 'active')->join('treatment_steps', 'treatment_steps.treatment_id', '=', 'treatments.id')->max('treatment_steps.staff_per_person');
        $therapists = $resources->clone()->whereHas('type', fn ($q) => $q->where('kind', 'therapist'))->count();

        return [
            __('admin.ready_hours') => $spa->hours()->exists(),
            __('admin.ready_treatments') => $treatments->clone()->where('price_solo', '>', 0)->where('duration_min', '>', 0)->exists()
                && ! $treatments->clone()->whereDoesntHave('steps')->exists()
                && ! $treatments->clone()->whereHas('steps', fn ($q) => $q->where('duration_min', '<=', 0))->exists(),
            __('admin.ready_resources') => $resources->clone()->exists()
                && ! $resources->clone()->where('capacity', '<', 1)->exists()
                && ! $treatments->clone()->whereHas('steps.resourceType', fn ($q) => $q->whereDoesntHave('resources', fn ($r) => $r->where('status', 'active')))->exists(),
            __('admin.ready_staff', ['n' => $maxStaff, 'have' => $therapists]) => $maxStaff === 0 || $therapists >= $maxStaff,
            __('admin.ready_rotation') => ! $spa->resourceTypes()->where('allocation_mode', 'unit')->where('kind', 'room')->whereNull('buffer_min')->exists(),
            __('admin.ready_published') => $spa->isPublished(),
        ];
    }

    public static function passes(Spa $spa): bool
    {
        return ! in_array(false, self::checks($spa), true);
    }

    /** @return list<string> */
    public static function failures(Spa $spa): array
    {
        return array_keys(array_filter(self::checks($spa), fn (bool $ok) => ! $ok));
    }
}
