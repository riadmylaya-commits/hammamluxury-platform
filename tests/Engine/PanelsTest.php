<?php

namespace Tests\Engine;

use App\Domain\Booking\BookingException;
use App\Domain\Catalogue\PublicationChecklist;
use App\Filament\Admin\Resources\SpaResource\Pages\EditSpa;
use App\Filament\Admin\Resources\SpaResource\Pages\ListSpas;
use App\Filament\Partner\Resources\BookingResource\Pages\ListBookings;
use App\Filament\Partner\Resources\BookingResource\Pages\ViewBooking;
use App\Filament\Partner\Resources\TreatmentResource\Pages\CreateTreatment;
use App\Filament\Partner\Resources\TreatmentResource\Pages\ListTreatments;
use App\Models\Partner;
use App\Models\Spa;
use App\Models\Treatment;
use App\Models\User;
use Filament\Facades\Filament;
use Livewire\Livewire;

/** Espaces Filament : accès par rôle, cloisonnement par établissement, actions réservation et validation admin. */
class PanelsTest extends BookingFlowTestCase
{
    private User $owner;

    private User $admin;

    private User $other;

    private Spa $otherSpa;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = $this->spa->partner->user;
        $this->admin = User::create(['name' => 'Admin', 'email' => 'admin@example.test', 'password' => 'secret-test', 'role' => 'admin']);
        $this->other = User::create(['name' => 'Autre', 'email' => 'autre@example.test', 'password' => 'secret-test', 'role' => 'partner']);
        $p = Partner::create(['user_id' => $this->other->id, 'company_name' => 'Autre SARL', 'status' => 'approved']);
        $this->otherSpa = Spa::create(['partner_id' => $p->id, 'slug' => 'autre-spa', 'name' => 'Autre Spa', 'city' => 'Fès', 'status' => 'draft']);
    }

    public function test_guests_are_redirected_to_login(): void
    {
        $this->get('/partenaire/'.$this->spa->slug)->assertRedirect('/partenaire/login');
        $this->get('/admin')->assertRedirect('/admin/login');
    }

    public function test_partner_is_scoped_to_own_spa_and_cannot_enter_admin(): void
    {
        $this->actingAs($this->owner)->get('/partenaire/'.$this->spa->slug)->assertOk()->assertSee($this->spa->name);
        $this->actingAs($this->owner)->get('/partenaire/'.$this->otherSpa->slug)->assertNotFound();
        $this->actingAs($this->owner)->get('/admin')->assertForbidden();
    }

    public function test_client_cannot_enter_partner_panel(): void
    {
        $client = User::create(['name' => 'Client', 'email' => 'c@example.test', 'password' => 'secret-test', 'role' => 'client']);
        $this->actingAs($client)->get('/partenaire')->assertForbidden();
    }

    public function test_admin_enters_both_panels_and_any_tenant(): void
    {
        $this->actingAs($this->admin)->get('/admin')->assertOk()->assertSee('Partenaires');
        $this->actingAs($this->admin)->get('/partenaire/'.$this->otherSpa->slug)->assertOk();
    }

    public function test_partner_sees_only_own_treatments_and_can_create_package(): void
    {
        $this->otherSpa->treatments()->create(['slug' => 'secret', 'name_fr' => 'Soin secret', 'category' => 'massage', 'price_solo' => 100, 'duration_min' => 30]);
        $this->actingAs($this->owner);
        Filament::setCurrentPanel(Filament::getPanel('partner'));
        Filament::setTenant($this->spa, true);

        Livewire::test(ListTreatments::class)->assertCanSeeTableRecords($this->spa->treatments)->assertSee('Hammam + Massage')->assertDontSee('Soin secret');

        $massage = $this->spa->resourceTypes->firstWhere('slug', 'massage');
        $hammam = $this->spa->resourceTypes->firstWhere('slug', 'hammam');
        Livewire::test(CreateTreatment::class)->fillForm([
            'name_fr' => 'Rituel Royal', 'category' => 'ritual', 'status' => 'active', 'price_solo' => 900, 'party_min' => 1, 'party_max' => 2,
            'steps' => [
                ['resource_type_id' => $hammam->id, 'duration_min' => 30],
                ['resource_type_id' => $massage->id, 'duration_min' => 90],
            ],
        ])->call('create')->assertHasNoFormErrors();

        $t = Treatment::where('slug', 'rituel-royal')->firstOrFail();
        $this->assertSame($this->spa->id, $t->spa_id);
        $this->assertSame(120, $t->duration_min);
        $this->assertSame([0, 30], $t->steps->pluck('offset_min')->all(), 'étapes séquentielles : offsets cumulés');
        $this->assertSame(150.0, (float) $this->spa->fresh()->price_from, 'price_from = soin le moins cher');
    }

    public function test_partner_accepts_and_declines_bookings_releasing_capacity(): void
    {
        $a = $this->submit($this->intent('10:00', ['treatment' => $this->hm->id, 'party' => 1]))['booking'];
        $b = $this->submit($this->intent('10:00', ['treatment' => $this->hm->id, 'party' => 1]))['booking'];
        try {
            $this->intent('10:00', ['treatment' => $this->m->id, 'party' => 1]);
            $this->fail('les 2 cabines sont prises à 10:00');
        } catch (BookingException $e) {
            $this->assertSame('unavailable', $e->reason);
        }

        $this->actingAs($this->owner);
        Filament::setCurrentPanel(Filament::getPanel('partner'));
        Filament::setTenant($this->spa, true);

        Livewire::test(ListBookings::class)->assertCanSeeTableRecords([$a, $b]);
        Livewire::test(ViewBooking::class, ['record' => $a->getRouteKey()])->assertSee($a->reference)->callAction('accept')->assertHasNoActionErrors();
        $this->assertSame('confirmed', $a->fresh()->status);

        Livewire::test(ViewBooking::class, ['record' => $b->getRouteKey()])->callAction('decline', ['note' => 'Cabine indisponible'])->assertHasNoActionErrors();
        $b->refresh();
        $this->assertSame('declined', $b->status);
        $this->assertSame(0, $b->allocations()->where('status', 'active')->count());
        $this->assertTrue($this->submit($this->intent('10:00', ['treatment' => $this->m->id, 'party' => 1]))['ok'], 'la cabine libérée est de nouveau réservable');

        Livewire::test(ViewBooking::class, ['record' => $b->getRouteKey()])->assertActionHidden('accept');
    }

    public function test_admin_cannot_publish_incomplete_spa(): void
    {
        $this->actingAs($this->admin);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->otherSpa->update(['status' => 'pending']);

        $this->assertFalse(PublicationChecklist::passes($this->otherSpa));
        $this->assertCount(6, PublicationChecklist::failures($this->otherSpa));

        Livewire::test(ListSpas::class)->callTableAction('publish', $this->otherSpa)->assertNotified();
        $this->assertSame('pending', $this->otherSpa->refresh()->status);

        Livewire::test(EditSpa::class, ['record' => $this->otherSpa->getRouteKey()])
            ->fillForm(['status' => 'published'])->call('save')->assertNotified();
        $this->assertSame('pending', $this->otherSpa->refresh()->status);
        $this->getJson('/api/v1/spas')->assertOk()->assertJsonPath('meta.total', 1);
    }

    public function test_admin_publishes_spa_once_checklist_passes(): void
    {
        $this->actingAs($this->admin);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $spa = $this->spa;
        $spa->update(['status' => 'pending', 'published_at' => null, 'category' => 'hammam', 'description_fr' => 'Description démo', 'address' => '1 rue Test', 'phone' => '+212600000000']);
        foreach (range(1, 9) as $i) {
            $spa->photos()->create(['path' => "/p/$i.jpg", 'sort_order' => $i, 'is_cover' => $i === 1]);
        }
        $this->assertSame([__('admin.chk_photos', ['min' => 10, 'n' => 9])], PublicationChecklist::failures($spa));
        Livewire::test(ListSpas::class)->callTableAction('publish', $spa);
        $this->assertSame('pending', $spa->refresh()->status);

        $spa->photos()->create(['path' => '/p/10.jpg', 'sort_order' => 10]);
        $this->assertTrue(PublicationChecklist::passes($spa));
        Livewire::test(ListSpas::class)->assertCanSeeTableRecords([$spa, $this->otherSpa])
            ->callTableAction('publish', $spa)->assertHasNoTableActionErrors();
        $spa->refresh();
        $this->assertSame('published', $spa->status);
        $this->assertNotNull($spa->published_at);
        $this->getJson('/api/v1/spas')->assertOk()->assertJsonPath('meta.total', 1);
    }
}
