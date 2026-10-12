<?php

namespace App\Domain\Booking;

use App\Models\Booking;
use App\Models\Spa;
use Illuminate\Support\Facades\DB;

/** Ordre commun : verrou de capacité du spa, transaction, puis lignes métier. */
class BookingMutation
{
    public function __construct(private CapacityEngine $engine) {}

    public function forSpa(Spa|int $spa, callable $operation): mixed
    {
        $id = $spa instanceof Spa ? (int) $spa->getKey() : $spa;
        $current = Spa::query()->findOrFail($id);

        return $this->engine->withLock($current, fn () => DB::transaction(
            fn () => $operation(Spa::query()->findOrFail($id)),
        ));
    }

    /** Le modèle transmis peut être périmé : aucune décision ne repose sur ses attributs. */
    public function forBooking(Booking $snapshot, callable $operation): mixed
    {
        $spaId = (int) Booking::query()->whereKey($snapshot->getKey())->firstOrFail()->spa_id;
        $current = null;
        $result = $this->forSpa($spaId, function (Spa $spa) use ($snapshot, $operation, &$current) {
            $current = Booking::query()->whereKey($snapshot->getKey())->where('spa_id', $spa->id)->lockForUpdate()->firstOrFail();
            $current->setRelation('spa', $spa);

            return $operation($current);
        });

        // Compatibilité avec les appelants qui continuent à utiliser leur instance après succès.
        $snapshot->setRawAttributes($current->getAttributes(), true);
        $snapshot->setRelations($current->getRelations());

        return $result;
    }
}
