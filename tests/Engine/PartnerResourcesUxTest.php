<?php

namespace Tests\Engine;

use App\Domain\Booking\CapacityEngine;
use App\Filament\Partner\Resources\ResourceTypeResource\Pages\CreateResourceType;
use App\Filament\Partner\Resources\ResourceTypeResource\Pages\EditResourceType;
use App\Filament\Partner\Resources\ResourceTypeResource\RelationManagers\ResourcesRelationManager;
use App\Models\ResourceType;
use Filament\Facades\Filament;
use Livewire\Livewire;

/** Écran Ressources partenaire : textes adaptés au type (hammam / massage / soin), saisie simplifiée des unités, moteur inchangé. */
class PartnerResourcesUxTest extends BookingFlowTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs($this->spa->partner->user);
        Filament::setCurrentPanel(Filament::getPanel('partner'));
        Filament::setTenant($this->spa, true);
    }

    public function test_family_is_detected_from_slug_or_name(): void
    {
        $this->assertSame('hammam', ResourceType::familyOf('hammam'));
        $this->assertSame('hammam', ResourceType::familyOf('grand-hammam', 'Grand Hammam'));
        $this->assertSame('massage', ResourceType::familyOf('massage'));
        $this->assertSame('massage', ResourceType::familyOf('cab', 'Cabines'));
        $this->assertSame('soin', ResourceType::familyOf('soin'));
        $this->assertSame('soin', ResourceType::familyOf('rooms', 'Treatment rooms'));
        $this->assertSame('other', ResourceType::familyOf('jacuzzi', 'Jacuzzi'));
        $this->assertSame('other', ResourceType::familyOf(null, null));
    }

    public function test_examples_follow_the_selected_resource_type(): void
    {
        $hammam = $this->spa->resourceTypes()->where('slug', 'hammam')->firstOrFail();
        $massage = $this->spa->resourceTypes()->where('slug', 'massage')->firstOrFail();
        $soin = $this->spa->resourceTypes()->where('slug', 'soin')->firstOrFail();

        Livewire::test(EditResourceType::class, ['record' => $hammam->getRouteKey()])
            ->assertSee('Comment cette ressource fonctionne-t-elle dans votre établissement ?')
            ->assertSee('hammam collectif de 6 personnes')
            ->assertSee('Hammam 1 = 4 personnes, Hammam 2 = 6 personnes')
            ->assertDontSee('Cabine duo = 2 personnes');

        Livewire::test(EditResourceType::class, ['record' => $massage->getRouteKey()])
            ->assertSee('Cabine 1 = 1 personne, Cabine 2 = 1 personne, Cabine duo = 2 personnes')
            ->assertDontSee('hammam collectif');

        Livewire::test(EditResourceType::class, ['record' => $soin->getRouteKey()])
            ->assertSee('Salle 1 = 1 personne, Salle 2 = 2 personnes')
            ->assertDontSee('hammam collectif')
            ->assertDontSee('Cabine duo');
    }

    public function test_examples_update_live_while_typing_a_new_type_name(): void
    {
        Livewire::test(CreateResourceType::class)
            ->assertSee('Unité 1 = 1 personne')
            ->fillForm(['name_fr' => 'Salle de soin'])
            ->assertSee('Salle 1 = 1 personne, Salle 2 = 2 personnes')
            ->fillForm(['name_fr' => 'Hammam privatif'])
            ->assertSee('Hammam 1 = 4 personnes, Hammam 2 = 6 personnes')
            ->fillForm(['name_fr' => 'Cabine de massage'])
            ->assertSee('Cabine duo = 2 personnes');
    }

    public function test_partner_adds_units_with_their_own_capacity_and_engine_uses_them(): void
    {
        $massage = $this->spa->resourceTypes()->where('slug', 'massage')->firstOrFail();
        $before = $massage->resources()->count();

        $rm = Livewire::test(ResourcesRelationManager::class, ['ownerRecord' => $massage, 'pageClass' => EditResourceType::class])
            ->mountTableAction('create')
            ->assertSee('Nombre de personnes par réservation')
            ->assertSee('1 pour une cabine solo, 2 pour une cabine duo')
            ->assertDontSee('Personnes min.');

        $rm->callTableAction('create', data: ['name' => 'Cabine duo', 'capacity' => 2, 'status' => 'active'])->assertHasNoTableActionErrors();
        $rm->callTableAction('create', data: ['name' => 'Cabine 3', 'capacity' => 1, 'status' => 'active'])->assertHasNoTableActionErrors();

        $duo = $massage->resources()->where('name', 'Cabine duo')->firstOrFail();
        $this->assertSame([2, 1, 2], [$duo->capacity, $duo->min_party, $duo->max_party], 'capacité = max personnes, min = 1');
        $this->assertSame($before + 2, $massage->resources()->count());

        $rm->callTableAction('edit', $duo, data: ['name' => 'Cabine duo', 'capacity' => 3, 'status' => 'active'])->assertHasNoTableActionErrors();
        $this->assertSame([3, 3], [$duo->fresh()->capacity, $duo->fresh()->max_party]);

        // Le moteur voit la nouvelle capacité : la cabine duo (3) + cabines solo.
        $free = app(CapacityEngine::class)->freeCapacity($this->spa->fresh(), $this->at('10:00'), $this->at('11:00'));
        $this->assertSame(3 + ($before + 1) * 1, $free['massage']);
    }

    public function test_pool_hammam_units_ask_for_simultaneous_people(): void
    {
        $hammam = $this->spa->resourceTypes()->where('slug', 'hammam')->firstOrFail();

        $rm = Livewire::test(ResourcesRelationManager::class, ['ownerRecord' => $hammam, 'pageClass' => EditResourceType::class])
            ->mountTableAction('create')
            ->assertSee('Nombre de personnes en même temps')
            ->assertSee('6 pour un hammam collectif de 6 places');

        $rm->callTableAction('create', data: ['name' => 'Hammam 2', 'capacity' => 4, 'status' => 'active'])->assertHasNoTableActionErrors();
        $h2 = $hammam->resources()->where('name', 'Hammam 2')->firstOrFail();
        $this->assertSame([4, 1, 4], [$h2->capacity, $h2->min_party, $h2->max_party]);
    }
}
