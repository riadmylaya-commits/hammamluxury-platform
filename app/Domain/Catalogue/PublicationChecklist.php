<?php

namespace App\Domain\Catalogue;

use App\Models\Spa;

/** Conditions minimales pour qu'une fiche soit publiée ; bloquant côté admin. */
class PublicationChecklist
{
    /** @return array<string, bool> clé de traduction admin.chk_* => satisfait */
    public static function checks(Spa $spa): array
    {
        $min = (int) config('hl.min_photos');
        $treatments = $spa->treatments()->where('status', 'active');

        return [
            __('admin.chk_identity') => filled($spa->name) && filled($spa->category) && filled($spa->city) && filled($spa->description_fr),
            __('admin.chk_photos', ['min' => $min, 'n' => $spa->photos()->count()]) => $spa->photos()->count() >= $min,
            __('admin.chk_address') => filled($spa->address) && filled($spa->phone),
            __('admin.chk_hours') => $spa->hours()->exists(),
            __('admin.chk_treatments') => $treatments->clone()->where('price_solo', '>', 0)->exists(),
            __('admin.chk_resources') => $spa->resources()->where('status', 'active')->exists()
                && ! $treatments->clone()->whereDoesntHave('steps')->exists()
                && ! $treatments->clone()->whereHas('steps.resourceType', fn ($q) => $q->whereDoesntHave('resources', fn ($r) => $r->where('status', 'active')))->exists(),
            __('admin.chk_partner') => $spa->partner?->isApproved() ?? false,
        ];
    }

    public static function passes(Spa $spa): bool
    {
        return ! in_array(false, self::checks($spa), true);
    }

    /** @return list<string> libellés des conditions non remplies */
    public static function failures(Spa $spa): array
    {
        return array_keys(array_filter(self::checks($spa), fn (bool $ok) => ! $ok));
    }
}
