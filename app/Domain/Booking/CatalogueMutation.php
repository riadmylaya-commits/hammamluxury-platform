<?php

namespace App\Domain\Booking;

use App\Models\Extra;
use App\Models\ResourceType;
use App\Models\Treatment;
use App\Models\TreatmentStep;
use Illuminate\Validation\ValidationException;

/** Les modifications de catalogue participent au même verrou que la confirmation. */
class CatalogueMutation
{
    public function __construct(private BookingMutation $mutations) {}

    public function run(Treatment|Extra|TreatmentStep $model, callable $operation): mixed
    {
        $current = $model->exists ? $model->newQuery()->findOrFail($model->getKey()) : null;
        $owner = $current ?? $model;
        $spaId = $owner instanceof TreatmentStep
            ? (int) Treatment::query()->findOrFail($owner->treatment_id)->spa_id
            : (int) $owner->spa_id;

        return $this->mutations->forSpa($spaId, function () use ($model, $operation, $spaId): mixed {
            $current = $model->exists ? $model->newQuery()->lockForUpdate()->findOrFail($model->getKey()) : null;
            $data = array_replace($current?->getAttributes() ?? [], $model->getDirty());
            if ($model instanceof TreatmentStep) {
                $treatment = Treatment::where('spa_id', $spaId)->find($data['treatment_id'] ?? null);
                $type = ResourceType::where('spa_id', $spaId)->find($data['resource_type_id'] ?? null);
                if (! $treatment || ! $type) {
                    $this->invalid('resource_type_id');
                }
            } else {
                if ((int) ($data['spa_id'] ?? 0) !== $spaId) {
                    $this->invalid('spa_id');
                }
                if ($model instanceof Extra && ! Treatment::where('spa_id', $spaId)->whereKey($data['treatment_id'] ?? null)->exists()) {
                    $this->invalid('treatment_id');
                }
            }

            return $operation();
        });
    }

    private function invalid(string $field): never
    {
        throw ValidationException::withMessages([$field => __('booking.resource_wrong_spa')]);
    }
}
