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
            ->assertSet('step', 3)->assertSee('délai d’annulation gratuite est dépassé', false)->assertDontSee('Annulation gratuite jusqu’au', false);

        $b = $this->submit($this->intent('17:00', ['treatment' => $this->h->id, 'party' => 1]))['booking'];
        foreach (['client', 'partner'] as $audience) {
            $mail = html_entity_decode((new BookingMail($b, 'created', $audience))->render());
            $this->assertStringNotContainsString('Annulation gratuite jusqu’au', $mail, "E-mail $audience : aucune date limite passée");
            $this->assertStringContainsString('délai d’annulation gratuite est dépassé', $mail);
            $this->assertStringContainsString('jusqu’à 24 h avant le rendez-vous', $mail, 'La règle de l’établissement reste rappelée telle quelle');
        }
        Livewire::test(BookingShow::class, ['token' => $b->manage_token])
            ->assertSee('délai d’annulation gratuite est dépassé', false)->assertDontSee('Annulation gratuite jusqu’au', false);
        $this->assertSame((float) $b->total, $b->cancelFeeNow(), 'Règle inchangée : passé le délai, 100 % reste dû');

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
}
