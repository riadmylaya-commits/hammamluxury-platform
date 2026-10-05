<?php

namespace Tests\Engine;

use App\Domain\Booking\CapacityEngine;
use App\Domain\Catalogue\PublicationChecklist;
use App\Domain\Partner\OnboardingService;
use App\Filament\Partner\Resources\TreatmentResource;
use App\Livewire\Site\BookingFlow;
use App\Models\Partner;
use App\Models\Spa;
use App\Models\User;
use Database\Seeders\ReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;
use Tests\TestCase;

/** Lot 2 — onboarding et interface partenaire : praticien(ne)s, temps de rotation, composantes parallèles, message client sans horaires. */
class CapacityOnboardingTest extends TestCase
{
    use RefreshDatabase;

    private Spa $spa;

    private OnboardingService $service;

    protected function setUp(): void
    {
        parent::setUp();
        URL::defaults(['locale' => 'fr']);
        app()->setLocale('fr');
        $this->seed(ReferenceSeeder::class);
        $user = User::create(['first_name' => 'Nadia', 'last_name' => 'Benali', 'email' => 'nadia@example.test', 'password' => 'secret-test', 'role' => 'partner', 'phone' => '+212661351989', 'email_verified_at' => now()]);
        $partner = Partner::create(['user_id' => $user->id, 'company_name' => 'Nadia SARL', 'status' => 'approved']);
        $this->service = app(OnboardingService::class);
        $this->spa = $this->service->saveSpa($partner, null, ['name' => 'Hammam Lot 2', 'category' => 'hammam', 'city_id' => 1, 'address' => '1 rue Test', 'description_fr' => 'Test', 'phone' => '+212524000000']);
    }

    public function test_onboarding_provisions_therapists_and_rotation_without_touching_other_units(): void
    {
        $this->service->saveTreatments($this->spa, [
            ['name_fr' => 'Massage relaxant', 'category' => 'massage', 'duration_min' => 60, 'price_solo' => 400],
            ['name_fr' => 'Massage 4 mains', 'category' => 'massage', 'duration_min' => 60, 'price_solo' => 800, 'staff_per_person' => 2],
            ['name_fr' => 'Hammam simple', 'category' => 'hammam', 'duration_min' => 45, 'price_solo' => 150],
        ]);
        $this->service->provisionResources($this->spa, ['hammam_capacity' => 6, 'massage_cabins' => 3, 'treatment_rooms' => 0, 'therapists' => 2, 'cabin_buffer_min' => 15]);

        $cap = $this->service->capacityOf($this->spa->fresh());
        $this->assertSame(['hammam_capacity' => 6, 'massage_cabins' => 3, 'treatment_rooms' => 0, 'therapists' => 2, 'cabin_buffer_min' => 15], $cap);

        $types = $this->spa->resourceTypes()->get()->keyBy('slug');
        $this->assertSame('therapist', $types['praticien']->kind);
        $this->assertSame(15, (int) $types['massage']->buffer_min);
        $this->assertSame(0, (int) $types['hammam']->buffer_min);

        $steps = $this->spa->treatments()->get()->keyBy('slug')->map(fn ($t) => $t->steps->map->only('staff_per_person')->all());
        $this->assertSame(1, (int) $steps['massage-relaxant'][0]['staff_per_person']);
        $this->assertSame(2, (int) $steps['massage-4-mains'][0]['staff_per_person']);
        $this->assertSame(0, (int) $steps['hammam-simple'][0]['staff_per_person']);

        // Réduction : les praticien(ne)s en trop passent inactifs, aucune suppression.
        $this->service->provisionResources($this->spa, ['hammam_capacity' => 6, 'massage_cabins' => 3, 'therapists' => 1]);
        $this->assertSame(1, $this->service->capacityOf($this->spa->fresh())['therapists']);
        $this->assertSame(2, $types['praticien']->resources()->count());
        $this->assertSame(15, (int) $types['massage']->fresh()->buffer_min, 'rotation inchangée quand elle n’est pas renvoyée');
    }

    public function test_therapists_required_rule(): void
    {
        $this->assertTrue(OnboardingService::therapistsMissing(['massage_cabins' => 2, 'therapists' => 0]));
        $this->assertFalse(OnboardingService::therapistsMissing(['massage_cabins' => 0, 'treatment_rooms' => 0, 'therapists' => 0]));
        $this->assertFalse(OnboardingService::therapistsMissing(['treatment_rooms' => 1, 'therapists' => 1]));
    }

    public function test_parallel_components_share_the_same_start_and_total_duration_is_the_longest_path(): void
    {
        $this->service->saveTreatments($this->spa, [[
            'name_fr' => 'Hammam + gommage + massage', 'category' => 'ritual', 'price_solo' => 650,
            'components' => [
                ['kind' => 'hammam', 'duration_min' => 45],
                ['kind' => 'soin', 'duration_min' => 30, 'label' => 'Gommage', 'parallel' => true, 'staff' => 1],
                ['kind' => 'massage', 'duration_min' => 60],
            ],
        ]]);

        $t = $this->spa->treatments()->firstOrFail();
        $steps = $t->steps->sortBy('position')->values();
        $this->assertSame([0, 0, 45], $steps->pluck('offset_min')->map(fn ($v) => (int) $v)->all());
        $this->assertSame([false, true, false], $steps->pluck('parallel_with_previous')->all());
        $this->assertSame([0, 1, 1], $steps->pluck('staff_per_person')->map(fn ($v) => (int) $v)->all());
        $this->assertSame(105, (int) $t->duration_min);
        $this->assertSame(105, $t->computedDuration());
        $this->assertSame([0, 0, 45], CapacityEngine::stepOffsets($t->steps));
    }

    public function test_partner_treatment_form_recomputes_offsets_with_parallel_steps(): void
    {
        $this->service->provisionResources($this->spa, ['hammam_capacity' => 4, 'treatment_rooms' => 1, 'therapists' => 1]);
        $types = $this->spa->resourceTypes()->get()->keyBy('slug');
        $t = $this->spa->treatments()->create(['slug' => 'rituel', 'name_fr' => 'Rituel', 'category' => 'ritual', 'duration_min' => 60, 'price_solo' => 500, 'party_min' => 1, 'party_max' => 4, 'status' => 'active']);
        $t->steps()->create(['resource_type_id' => $types['hammam']->id, 'duration_min' => 40, 'offset_min' => 99, 'position' => 0, 'parallel_with_previous' => true]);
        $t->steps()->create(['resource_type_id' => $types['soin']->id, 'duration_min' => 20, 'offset_min' => 99, 'position' => 1, 'parallel_with_previous' => true, 'staff_per_person' => 1]);
        $t->steps()->create(['resource_type_id' => $types['soin']->id, 'duration_min' => 30, 'offset_min' => 99, 'position' => 2, 'staff_per_person' => 1]);

        TreatmentResource::syncDuration($t);

        $steps = $t->fresh()->steps->sortBy('position')->values();
        $this->assertSame([0, 0, 40], $steps->pluck('offset_min')->map(fn ($v) => (int) $v)->all());
        $this->assertFalse($steps[0]->parallel_with_previous, 'la première étape ne peut pas être parallèle');
        $this->assertSame(70, (int) $t->fresh()->duration_min);
    }

    public function test_publication_requires_an_active_therapist_when_a_treatment_needs_one(): void
    {
        $this->service->saveTreatments($this->spa, [['name_fr' => 'Massage', 'category' => 'massage', 'duration_min' => 60, 'price_solo' => 400]]);
        $this->service->provisionResources($this->spa, ['massage_cabins' => 1, 'therapists' => 0]);
        $this->assertFalse(PublicationChecklist::staffCovered($this->spa->fresh()));
        $this->assertContains(__('admin.chk_staff'), PublicationChecklist::failures($this->spa->fresh()));

        $this->service->provisionResources($this->spa, ['massage_cabins' => 1, 'therapists' => 1]);
        $this->assertTrue(PublicationChecklist::staffCovered($this->spa->fresh()));

        $this->spa->resources()->whereHas('type', fn ($q) => $q->where('kind', 'therapist'))->update(['status' => 'inactive']);
        $this->assertFalse(PublicationChecklist::staffCovered($this->spa->fresh()));
    }

    public function test_booking_flow_explains_immediately_when_no_hours_are_set(): void
    {
        $this->service->saveTreatments($this->spa, [['name_fr' => 'Hammam', 'category' => 'hammam', 'duration_min' => 45, 'price_solo' => 150]]);
        $this->service->provisionResources($this->spa, ['hammam_capacity' => 6]);
        $this->spa->update(['status' => 'published']);
        $treatment = $this->spa->treatments()->firstOrFail();

        Livewire::test(BookingFlow::class, ['spa' => $this->spa->fresh()])
            ->call('selectTreatment', $treatment->id)->call('next')->assertSet('step', 2)
            ->assertSee(__('ui.no_slots_at_all'))
            ->assertSee(__('booking.not_configured'));
    }
}
