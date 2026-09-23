<?php

namespace Tests\Engine;

use App\Livewire\Site\BookingFlow;
use App\Livewire\Site\BookingShow;
use App\Models\Booking;
use Livewire\Livewire;

/** Pages publiques Livewire FR/EN : rendu, réservation invitée de bout en bout, refus de créneau, suivi et annulation. */
class SiteFlowTest extends BookingFlowTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        \Illuminate\Support\Facades\URL::defaults(['locale' => 'fr']);
        app()->setLocale('fr');
    }

    public function test_pages_render_in_both_locales(): void
    {
        $this->get('/')->assertRedirect('/en');
        $this->withHeader('Accept-Language', 'fr-FR,fr;q=0.9')->get('/')->assertRedirect('/fr');
        $this->get('/fr')->assertOk()->assertSee('Trouvez votre hammam')->assertSee($this->spa->name);
        $this->get('/en')->assertOk()->assertSee('Find your hammam')->assertSee('hreflang="fr"', false);
        $this->get('/fr/recherche?q=marrakech')->assertOk()->assertSee($this->spa->name)->assertSee('1 établissement');
        $this->get('/en/recherche?q=nulle-part')->assertOk()->assertSee('No venue matches');
        $this->get('/fr/recherche?category=massage')->assertOk()->assertSee($this->spa->name);
        $this->get('/fr/recherche?category=face')->assertOk()->assertSee('Aucun établissement');
        $this->get('/fr/spa/'.$this->spa->slug)->assertOk()->assertSee('Hammam + Massage')->assertSee('Massage crânien')->assertSee('+15 min')->assertSee('Fermé')->assertDontSee($this->spa->phone);
        $this->get('/en/spa/'.$this->spa->slug)->assertOk()->assertSee('Treatments &amp; prices', false)->assertSee('Package');
        $this->get('/de/spa/'.$this->spa->slug)->assertNotFound();
        $this->spa->update(['status' => 'pending']);
        $this->get('/fr/spa/'.$this->spa->slug)->assertNotFound();
    }

    public function test_guest_booking_end_to_end_with_extra_and_recap(): void
    {
        $c = Livewire::withQueryParams(['soin' => $this->hm->id])->test(BookingFlow::class, ['spa' => $this->spa])
            ->assertSet('treatment', $this->hm->id)->assertSee('1 pers.')
            ->call('changeParty', 1)->assertSet('party', 2)
            ->call('toggleExtra', $this->cr->id)->assertSee('1 350 MAD')->assertSee('135 min')
            ->call('next')->assertSet('step', 2)->assertSet('error', '')
            ->call('pickDate', $this->day)->assertSee('10:00')->assertSee('11:30')->assertDontSee('>12:00<', false)
            ->call('pickTime', '10:00')->assertSee('12:15')
            ->call('next')->assertSet('step', 3);
        $this->assertNotSame('', $c->get('intentToken'), 'Intent signé créé côté serveur à l’entrée de l’étape 3');
        $c->set('first_name', 'Test')->set('last_name', 'Client')->set('email', 'bad')->set('phone', '0600000000')->set('terms', true)
            ->call('submit')->assertHasErrors(['email'])
            ->set('email', 'client@example.test')->set('hotel', 'Riad Test')->set('note', 'Mon WhatsApp +212 6 11 22 33 44 merci')
            ->call('submit')->assertHasNoErrors();
        $b = Booking::latest('id')->first();
        $this->assertNotNull($b, 'Réservation créée depuis le parcours Livewire');
        $c->assertRedirect(route('booking.show', ['locale' => 'fr', 'token' => $b->manage_token, 'new' => 1]));
        $this->assertTrue(1350.0 === (float) $b->total && 2 === $b->party && '12:15' === $b->end_at->format('H:i') && 'Riad Test' === $b->hotel, 'Prix/durée/fin recalculés côté serveur');
        $this->assertStringNotContainsString('11 22 33 44', (string) $b->note, 'Coordonnées masquées dans le message client');

        $this->get('/fr/reservation/'.$b->manage_token.'?new=1')->assertOk()->assertSee('Demande envoyée')->assertSee($b->reference)->assertSee('En attente de confirmation')->assertDontSee($this->spa->phone);
        $this->get('/en/reservation/'.$b->manage_token)->assertOk()->assertSee('Awaiting confirmation');
        $this->bookings->accept($b);
        $this->get('/fr/reservation/'.$b->manage_token)->assertOk()->assertSee('Confirmée')->assertSee($this->spa->phone);
    }

    public function test_advanced_mode_and_slot_gone(): void
    {
        $c = Livewire::test(BookingFlow::class, ['spa' => $this->spa])
            ->call('selectTreatment', $this->hm->id)->call('changeParty', 1)
            ->call('toggleAdvanced')->assertSet('advanced', true)->assertSee('Personne 2')
            ->call('setParticipantTreatment', 1, $this->hs->id)->assertSee('1 150 MAD')
            ->call('toggleExtra', $this->cr->id, 0)->assertSee('1 275 MAD')
            ->call('next')->assertSet('step', 2)->call('pickDate', $this->day)->call('pickTime', '16:00')->call('next')->assertSet('step', 3);
        // Un autre client prend la salle de soin entre-temps : la validation finale sous verrou refuse et renvoie à l’étape 2.
        $this->book('Concurrent', '16:00', [['hammam-soin', 1]], true);
        $c->set('first_name', 'A')->set('last_name', 'B')->set('email', 'a@b.test')->set('phone', '0600000001')->set('terms', true)
            ->call('submit')->assertSet('step', 2)->assertSet('time', '')->assertSee('n’est plus disponible');
        $this->assertSame(1, Booking::count(), 'Aucune réservation insérée pour le créneau perdu');
    }

    public function test_client_cancel_from_tracking_page(): void
    {
        $b = $this->submit($this->intent('10:00', ['treatment' => $this->m->id, 'party' => 1]))['booking'];
        Livewire::test(BookingShow::class, ['token' => $b->manage_token])->assertSee('Annuler ma réservation')
            ->call('cancel')->assertSee('Votre réservation a été annulée')->assertDontSee('Annuler ma réservation');
        $this->assertSame('cancelled', $b->fresh()->status);
        $this->assertContains('10:00', $this->availability(['treatment' => $this->m->id, 'party' => 2])['times'], 'Cabine libérée après annulation client');
    }
}
