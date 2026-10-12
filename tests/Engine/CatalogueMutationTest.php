<?php

namespace Tests\Engine;

use App\Models\ResourceType;
use Illuminate\Validation\ValidationException;

class CatalogueMutationTest extends BookingFlowTestCase
{
    public function test_extra_cannot_be_moved_to_another_spa(): void
    {
        $other = $this->spa('catalogue-other');
        $this->expectException(ValidationException::class);
        $this->cr->update(['spa_id' => $other->id]);
    }

    public function test_step_cannot_reference_a_resource_type_from_another_spa(): void
    {
        $other = $this->spa('catalogue-step-other');
        $type = ResourceType::create(['spa_id' => $other->id, 'slug' => 'other-room', 'name_fr' => 'Autre cabine',
            'allocation_mode' => 'unit', 'kind' => 'room']);
        $this->expectException(ValidationException::class);
        $this->hm->steps()->firstOrFail()->update(['resource_type_id' => $type->id]);
    }

    public function test_treatment_cannot_be_reassigned_to_another_spa(): void
    {
        $other = $this->spa('catalogue-treatment-other');
        $this->expectException(ValidationException::class);
        $this->hm->update(['spa_id' => $other->id]);
    }
}
