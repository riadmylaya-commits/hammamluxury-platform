<?php

namespace Tests\Engine;

use App\Livewire\Site\BookingFlow;
use App\Livewire\Site\BookingShow;
use App\Mail\BookingMail;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;

/** Date limite d'annulation gratuite : jamais annoncée lorsqu'elle est déjà passée, sur le site comme dans les e-mails, en français et en anglais. */
class CancellationNoticeTest extends BookingFlowTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        URL::defaults(['locale' => 'fr']);
        app()->setLocale('fr');
    }

    private function on(string $time, int $addDays = 0): CarbonImmutable
    {
        return CarbonImmutable::parse($this->day)->addDays($addDays)->setTimeFromTimeString($time);
    }

    public function test_request_made_less_than_24_hours_ahead_never_announces_a_past_deadline(): void
    {
        // Règle existante de la fiche : annulation gratuite jusqu'à 24 h avant. Demande la veille à 20 h pour 17 h : la limite (la veille 17 h) est déjà passée.
        $this->travelTo($this->on('20:00', -1));
        Livewire::test(BookingFlow::class, ['spa' => $this->spa])
            ->call('selectTreatment', $this->h->id)->call('next')->call('pickDate', $this->day)->call('pickTime', '17:00')->call('next')
            ->assertSet('step', 3)->assertSee('vous pouvez l’annuler sans frais', false)->assertSee('délai d’annulation gratuite est déjà dépassé', false)->assertDontSee('Annulation gratuite jusqu’au', false);

        $b = $this->submit($this->intent('17:00', ['treatment' => $this->h->id, 'party' => 1]))['booking'];
        foreach (['client', 'partner'] as $audience) {
            $mail = html_entity_decode((new BookingMail($b, 'created', $audience))->render());
            $this->assertStringNotContainsString('Annulation gratuite jusqu’au', $mail, "E-mail $audience : aucune date limite passée");
            $this->assertStringContainsString('vous pouvez l’annuler sans frais', $mail, 'Demande en attente : aucuns frais avant confirmation');
            $this->assertStringContainsString('une fois la réservation confirmée, 100 %', $mail);
            $this->assertStringContainsString('jusqu’à 24 h avant le rendez-vous', $mail, 'La règle de l’établissement reste rappelée telle quelle');
        }
        Livewire::test(BookingShow::class, ['token' => $b->manage_token])
            ->assertSee('vous pouvez l’annuler sans frais', false)->assertDontSee('Annulation gratuite jusqu’au', false);
        $this->assertSame(0.0, $b->cancelFeeNow(), 'Demande pas encore confirmée : aucuns frais');

        // Une fois confirmée par l'établissement, la règle existante s'applique : passé le délai, 100 % reste dû.
        $confirmed = $this->bookings->accept($b->fresh());
        $this->assertSame((float) $b->total, $confirmed->cancelFeeNow(), 'Réservation confirmée : règle de l’établissement inchangée');
        Livewire::test(BookingShow::class, ['token' => $b->manage_token])
            ->assertSee('délai d’annulation gratuite est dépassé', false)->assertDontSee('sans frais');
        $b = $confirmed;

        $b->update(['locale' => 'en']);
        app()->setLocale('en');
        $en = html_entity_decode((new BookingMail($b->fresh(), 'created', 'client'))->render());
        $this->assertStringContainsString('free-cancellation deadline has passed', $en);
        $this->assertStringNotContainsString('Free cancellation until', $en);
    }

    public function test_request_made_well_ahead_still_states_the_deadline_everywhere(): void
    {
        $this->travelTo($this->on('10:00', -3));
        Livewire::test(BookingFlow::class, ['spa' => $this->spa])
            ->call('selectTreatment', $this->h->id)->call('next')->call('pickDate', $this->day)->call('pickTime', '17:00')->call('next')
            ->assertSee('Annulation gratuite jusqu’au', false)->assertDontSee('est dépassé');
        $b = $this->submit($this->intent('17:00', ['treatment' => $this->h->id, 'party' => 1]))['booking'];
        $mail = html_entity_decode((new BookingMail($b, 'created', 'client'))->render());
        $this->assertStringContainsString('Annulation gratuite jusqu’au', $mail);
        $this->assertStringContainsString('17h00', $mail);
        $this->assertSame(0.0, $b->cancelFeeNow());

        // Même réservation relue après la limite : le texte bascule, la règle ne change pas.
        $this->travelTo($this->on('18:00', -1));
        $late = html_entity_decode((new BookingMail($b->fresh(), 'confirmed', 'client'))->render());
        $this->assertStringNotContainsString('Annulation gratuite jusqu’au', $late);
    }

    public function test_cancelling_before_confirmation_is_free_and_owes_no_commission(): void
    {
        $this->travelTo($this->on('20:00', -1));
        $b = $this->submit($this->intent('17:00', ['treatment' => $this->h->id, 'party' => 1]))['booking'];
        $cancelled = $this->bookings->cancel($b->fresh(), 'client');
        $this->assertSame('cancelled', $cancelled->status);
        $this->assertNull($cancelled->cancel_fee, 'Aucuns frais : la demande n’était pas confirmée');
        $this->assertTrue($cancelled->events()->where('type', 'cancel:free')->exists());
        $this->assertTrue($cancelled->nothingDue());
        $this->assertSame(0.0, $cancelled->commissionDue(), 'Aucune commission sur une demande annulée avant confirmation');

        // Même situation après confirmation : la règle de l'établissement (100 % passé le délai) et la commission restent celles d'avant.
        $c = $this->submit($this->intent('17:00', ['treatment' => $this->m->id, 'party' => 1]))['booking'];
        $this->bookings->accept($c->fresh());
        $late = $this->bookings->cancel($c->fresh(), 'client');
        $this->assertSame((float) $late->total, (float) $late->cancel_fee);
        $this->assertTrue($late->events()->where('type', 'cancel:late_fee')->exists());
        $this->assertGreaterThan(0.0, $late->commissionDue());
    }
}
