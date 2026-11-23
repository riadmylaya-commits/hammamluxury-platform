<?php

namespace Tests\Engine;

use App\Domain\Booking\BookingException;
use App\Filament\Partner\Widgets\PendingBookings;
use App\Mail\BookingMail;
use App\Models\City;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;

/** Demandes en attente du partenaire (cartes sur téléphone, tableau sur ordinateur) : mêmes actions, jamais de double traitement. */
class PendingBookingsCardsTest extends BookingFlowTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->spa->update(['city_id' => City::first()?->id ?? City::create(['slug' => 'marrakech', 'name_fr' => 'Marrakech', 'name_en' => 'Marrakech'])->id]);
        $this->reload();
    }

    private function asOwner(): void
    {
        $this->actingAs($this->spa->partner->user);
        Filament::setCurrentPanel(Filament::getPanel('partner'));
        Filament::setTenant($this->spa, true);
    }

    public function test_decline_needs_a_reason_and_the_internal_note_never_reaches_the_client(): void
    {
        $d = $this->submit($this->intent('10:00', ['treatment' => $this->h->id, 'party' => 1]))['booking'];
        $this->asOwner();
        Mail::fake();

        $list = Livewire::test(PendingBookings::class)->mountTableAction('decline', $d);
        $submit = fn () => $list->instance()->getMountedTableAction()->getModalSubmitAction();
        $this->assertTrue($submit()->isDisabled(), '« Confirmer » inactif tant que le motif manque');
        $this->assertSame('true', $submit()->getExtraAttributes()['x-bind:disabled'] ?? null, 'réellement désactivé dans le navigateur, clavier compris');
        $list->assertDontSee('Motif choisi');
        $list->setTableActionData(['reason' => 'other']);
        $this->assertFalse($submit()->isDisabled());
        $this->assertArrayNotHasKey('x-bind:disabled', $submit()->getExtraAttributes());
        // Le motif choisi est répété en entier sous la liste (un libellé long est coupé dans la liste native d'un petit écran).
        $list->assertSee('Motif choisi : Autre (à préciser).')->setTableActionData(['reason' => 'closed'])
            ->assertSee('Motif choisi : Établissement fermé / horaires exceptionnels.')
            ->setTableActionData(['reason' => 'full'])->assertSee('Motif choisi : Plus de place à cet horaire.')->assertSee('Si vous refusez souvent');

        // Sans motif, rien n'est enregistré même si la demande est envoyée.
        $list->callTableAction('decline', $d, data: ['reason' => null])->assertHasTableActionErrors(['reason']);
        $this->assertSame('waiting', $d->fresh()->status);

        $list->callTableAction('decline', $d, data: ['reason' => 'other', 'note' => 'NOTE-INTERNE-XYZ'])->assertHasNoTableActionErrors();
        $this->assertSame('declined', $d->fresh()->status);
        $this->assertSame('NOTE-INTERNE-XYZ', $d->fresh()->decline->note);
        foreach (['fr', 'en'] as $locale) {
            app()->setLocale($locale);
            $html = (new BookingMail($d->fresh(), 'declined', 'client'))->render();
            $this->assertStringNotContainsString('NOTE-INTERNE-XYZ', $html);
            $this->assertStringNotContainsString(__('partner.decline_reasons', [], 'fr')['other'], $html);
        }
    }

    public function test_accept_and_decline_work_once_from_the_pending_list(): void
    {
        $a = $this->submit($this->intent('10:00', ['treatment' => $this->h->id, 'party' => 1]))['booking'];
        $d = $this->submit($this->intent('17:00', ['treatment' => $this->m->id, 'party' => 1]))['booking'];
        $this->asOwner();
        Mail::fake();

        $list = Livewire::test(PendingBookings::class)
            ->assertSee('Répondre')->assertSee('Détails')
            ->assertTableActionVisible('accept', $a)->assertTableActionVisible('decline', $d)
            ->callTableAction('accept', $a)->assertHasNoTableActionErrors();
        $this->assertSame('confirmed', $a->fresh()->status);
        Mail::assertQueuedCount(0);
        Mail::assertSent(BookingMail::class, fn ($m) => $m->event === 'confirmed' && $m->booking->is($a) && $m->audience === 'client');
        $sent = Mail::sent(BookingMail::class)->count();

        // Deuxième appui (double clic, page restée ouverte) : la demande n'est plus proposée, rien n'est renvoyé.
        $list->assertTableActionHidden('accept', $a->fresh())->assertTableActionHidden('decline', $a->fresh());
        try {
            $this->bookings->accept($a->fresh());
            $this->fail('Une demande déjà confirmée ne peut pas être acceptée une seconde fois');
        } catch (BookingException) {
            $this->assertSame($sent, Mail::sent(BookingMail::class)->count(), 'Aucune notification en double');
        }
        $this->assertSame(1, $a->events()->where('type', 'confirmed')->count(), 'Une seule confirmation enregistrée');
        $this->assertSame(1, $a->events()->where('type', 'notified:confirmed')->count(), 'Une seule notification de confirmation');

        $list->callTableAction('decline', $d, data: ['reason' => 'full'])->assertHasNoTableActionErrors();
        $this->assertSame('declined', $d->fresh()->status);
        $list->assertTableActionHidden('decline', $d->fresh())->assertTableActionHidden('accept', $d->fresh());
        try {
            $this->bookings->accept($d->fresh());
            $this->fail('Une demande refusée ne peut plus être acceptée');
        } catch (BookingException) {
            $this->assertSame('declined', $d->fresh()->status);
        }
    }
}
