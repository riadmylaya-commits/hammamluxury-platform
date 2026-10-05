<?php

namespace Tests\Engine;

use App\Domain\Booking\BookingException;
use App\Livewire\Site\BookingFlow;
use App\Livewire\Site\BookingShow;
use App\Models\Booking;
use App\Models\LedgerEntry;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;

/**
 * Session 3 — politique d'annulation choisie par le partenaire, tarif non remboursable, conditions figées,
 * annulation gratuite / tardive / NR avec frais + commission, créneau toujours libéré.
 */
class CancellationPolicyTest extends BookingFlowTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        URL::defaults(['locale' => 'fr']);
        app()->setLocale('fr');
        config(['hl.features.non_refundable' => true]);
    }

    public function test_non_refundable_rate_is_fully_hidden_when_feature_disabled(): void
    {
        config(['hl.features.non_refundable' => false]);
        $this->hm->update(['nr_discount_pct' => 15]);
        $this->assertFalse($this->hm->fresh()->hasNonRefundable(), 'Réduction enregistrée mais tarif NR non proposé');
        $q = $this->quote(['participants' => [['treatment' => $this->hm->id]], 'rate' => 'nr']);
        $this->assertTrue($q['ok'] && $q['rate'] === 'standard' && $q['nr_available'] === false && (float) $q['total'] === 600.0, 'Une demande NR retombe sur le tarif standard');
        $b = $this->submit($this->intent('10:00', ['treatment' => $this->hm->id, 'party' => 1, 'rate' => 'nr']))['booking'];
        $this->assertTrue($b->rate_type === 'standard' && (float) $b->total === 600.0 && $b->nr_discount_pct === null, 'Réservation créée en Standard plein tarif');
        $html = Livewire::test(BookingFlow::class, ['spa' => $this->spa])->set('treatment', $this->hm->id)->set('date', $b->start_at->toDateString())->set('time', '14:00')->html();
        $this->assertStringNotContainsString(__('ui.policy.non_refundable'), $html, 'Aucune mention Non remboursable dans le tunnel');
        $this->assertStringNotContainsString(__('ui.policy.rate_title'), $html, 'Pas de choix de tarif');
    }

    public function test_policy_frozen_and_treatment_override_is_strictest(): void
    {
        $this->spa->update(['cancellation_hours' => 48]);
        $b = $this->submit($this->intent('10:00', ['treatment' => $this->hm->id, 'party' => 1]))['booking'];
        $this->assertTrue($b->rate_type === 'standard' && $b->cancellationHours() === 48 && (float) $b->standard_total === 600.0 && $b->nr_discount_pct === null, 'Conditions figées : standard, 48 h, prix de référence 600');
        $this->assertSame($b->start_at->subHours(48)->toDateTimeString(), $b->freeCancellationUntil()->toDateTimeString(), 'Limite = début − 48 h');

        $this->spa->update(['cancellation_hours' => 8]);
        $this->hm->update(['cancellation_hours' => 168]);
        $this->assertTrue($b->fresh()->cancellationHours() === 48, 'Un changement ultérieur de politique ne modifie pas la réservation existante');

        $q = $this->quote(['participants' => [['treatment' => $this->hm->id], ['treatment' => $this->h->id]]]);
        $this->assertTrue($q['ok'] && $q['cancellation_hours'] === 168, 'Surcharge prestation (7 jours) prime sur l’établissement (8 h) : le délai le plus strict s’applique au devis ('.$q['cancellation_hours'].')');

        $this->hm->update(['cancellation_hours' => 37]);
        $this->assertNull($this->hm->fresh()->cancellation_hours, 'Un délai hors liste est ignoré');
    }

    public function test_free_cancellation_before_deadline_then_late_fee_after(): void
    {
        $sel = ['treatment' => $this->hm->id, 'party' => 2, 'extras' => [$this->cr->id]];
        $b = $this->submit($this->intent('10:00', $sel))['booking'];
        $this->bookings->accept($b);
        $this->assertTrue($b->fresh()->freeCancellationOpen() && $b->fresh()->cancelFeeNow() === 0.0, 'Avant la limite (24 h) : annulation gratuite');
        $this->bookings->cancel($b->fresh(), 'client');
        $b->refresh();
        $this->assertTrue($b->status === 'cancelled' && $b->cancel_fee === null && $b->nothingDue() && $b->commissionDue() === 0.0 && $this->activeAllocations($b) === [] && in_array('10:00', $this->availability($sel)['times'], true), 'Gratuite : rien de dû, 0 commission, créneau libéré');
        $this->assertSame(0, LedgerEntry::where('booking_id', $b->id)->count(), 'Aucune écriture de commission');
        $this->assertTrue($b->events()->where('type', 'cancel:free')->exists(), 'Historique cancel:free');

        $late = $this->submit($this->intent('17:30', $sel))['booking'];
        $this->bookings->accept($late);
        CarbonImmutable::setTestNow($late->start_at->subHours(3));
        $late->refresh();
        $this->assertTrue(! $late->freeCancellationOpen() && $late->cancelFeeNow() === 1350.0, 'À −3 h : délai dépassé, 100 % dû');
        $this->bookings->cancel($late, 'client');
        $late->refresh();
        $this->assertTrue($late->status === 'cancelled' && (float) $late->cancel_fee === 1350.0 && ! $late->nothingDue() && $late->commissionDue() === 202.5 && $this->activeAllocations($late) === [], 'Tardive : 1 350 dus, commission 202,50 conservée, créneau libéré');
        $this->assertSame(1, LedgerEntry::where('booking_id', $late->id)->where('type', 'commission')->count(), 'Écriture de commission créée');
        $this->assertTrue($late->events()->where('type', 'cancel:late_fee')->exists(), 'Historique cancel:late_fee');
        CarbonImmutable::setTestNow();
    }

    public function test_non_refundable_rate_discount_validation_and_cancellation(): void
    {
        $q = $this->quote(['treatment' => $this->hm->id, 'party' => 1, 'rate' => 'non_refundable']);
        $this->assertTrue(! $q['ok'] && ! $q['nr_available'], 'Tarif NR refusé si la prestation ne le propose pas');

        $this->hm->update(['nr_discount_pct' => 5]);
        $this->assertNull($this->hm->fresh()->nr_discount_pct, 'Réduction < 10 % ignorée');
        $this->hm->update(['nr_discount_pct' => 15]);

        $std = $this->quote(['treatment' => $this->hm->id, 'party' => 2, 'extras' => [$this->cr->id]]);
        $nr = $this->quote(['treatment' => $this->hm->id, 'party' => 2, 'extras' => [$this->cr->id], 'rate' => 'nr']);
        $this->assertTrue($nr['ok'] && $nr['rate'] === 'non_refundable' && (float) $nr['total'] === 1185.0 && (float) $nr['standard_total'] === 1350.0 && $nr['nr_discount_pct'] === 15, 'NR : 1 100 × 0,85 = 935 + extra 250 (non réduit) = 1 185 ; référence 1 350 ('.$nr['total'].')');
        $this->assertNotSame($std['fingerprint'], $nr['fingerprint'], 'Empreintes distinctes standard / NR');

        $b = $this->submit($this->intent('10:00', ['treatment' => $this->hm->id, 'party' => 2, 'extras' => [$this->cr->id], 'rate' => 'non_refundable']))['booking'];
        $this->assertTrue($b->isNonRefundable() && (float) $b->total === 1185.0 && (float) $b->standard_total === 1350.0 && $b->nr_discount_pct === 15 && $b->nrSaving() === 165.0 && $b->freeCancellationUntil() === null, 'Réservation NR figée : 1 185, référence 1 350, −15 %, économie 165');
        $this->assertTrue((float) $b->commission_amount === round(1185.0 * 0.15, 2), 'Commission calculée sur le prix NR réellement facturé');
        $this->bookings->accept($b);

        $this->hm->update(['nr_discount_pct' => 40, 'price_solo' => 900]);
        $this->assertTrue((float) $b->fresh()->total === 1185.0 && $b->fresh()->nr_discount_pct === 15, 'Changement de tarif ultérieur sans effet sur la réservation');

        $this->bookings->cancel($b->fresh(), 'client');
        $b->refresh();
        $this->assertTrue($b->status === 'cancelled' && (float) $b->cancel_fee === 1185.0 && ! $b->nothingDue() && $b->commissionDue() === round(1185.0 * 0.15, 2) && $this->activeAllocations($b) === [], 'Annulation NR : créneau libéré, 100 % dus, commission conservée');
        $this->assertTrue($b->events()->where('type', 'cancel:non_refundable')->exists() && LedgerEntry::where('booking_id', $b->id)->where('type', 'commission')->exists(), 'Historique + écriture de commission');
    }

    public function test_partner_or_admin_cancellation_never_charges_client(): void
    {
        $this->hm->update(['nr_discount_pct' => 20]);
        $b = $this->submit($this->intent('10:00', ['treatment' => $this->hm->id, 'party' => 1, 'rate' => 'nr']))['booking'];
        $this->bookings->accept($b);
        CarbonImmutable::setTestNow($b->start_at->subHour());
        $this->bookings->cancel($b->fresh(), 'admin');
        $b->refresh();
        $this->assertTrue($b->status === 'cancelled' && $b->cancel_fee === null && $b->nothingDue() && $b->commissionDue() === 0.0 && LedgerEntry::where('booking_id', $b->id)->doesntExist(), 'Annulation acceptée par HammamLuxury : rien de dû même en NR à −1 h');
        CarbonImmutable::setTestNow();

        try {
            $this->bookings->cancel($b->fresh(), 'client');
            $this->fail('Double annulation');
        } catch (BookingException $e) {
            $this->assertSame('status', $e->reason);
        }
    }

    public function test_livewire_flow_rate_choice_and_tracking_page(): void
    {
        $this->spa->update(['cancellation_hours' => 72]);
        $c = Livewire::withQueryParams(['soin' => $this->m->id])->test(BookingFlow::class, ['spa' => $this->spa])
            ->assertSee('Choisissez votre tarif')->assertSee('Tarif Standard')->assertSee('72 h')->assertDontSee('Non remboursable')
            ->call('setRate', 'non_refundable')->assertSet('rate', 'standard');

        $this->m->update(['nr_discount_pct' => 10]);
        $c = Livewire::withQueryParams(['soin' => $this->m->id, 'tarif' => 'non_refundable'])->test(BookingFlow::class, ['spa' => $this->spa])
            ->assertSet('rate', 'non_refundable')->assertSee('Non remboursable')->assertSee('−10 %')->assertSee('360 MAD')
            ->call('next')->assertSet('step', 2)
            ->call('pickDate', $this->day)->call('pickTime', '10:00')->call('next')->assertSet('step', 3)
            ->assertSee('Conditions d’annulation')->assertSee('100 % du montant (360 MAD)')
            ->set('first_name', 'Test')->set('last_name', 'Client')->set('email', 'client@example.test')->set('phone', '0600000000')->set('terms', true)
            ->call('submit')->assertHasNoErrors();
        $b = Booking::latest('id')->first();
        $this->assertTrue($b->isNonRefundable() && (float) $b->total === 360.0 && (float) $b->standard_total === 400.0 && $b->cancellationHours() === 72, 'Réservation NR 360 (réf. 400), 72 h figés');

        $this->get('/fr/reservation/'.$b->manage_token)->assertOk()->assertSee('Non remboursable · −10 %')->assertSee('Ces conditions ont été acceptées');
        Livewire::test(BookingShow::class, ['token' => $b->manage_token])->assertSee('100 % du montant (360 MAD) restera dû')->call('cancel');
        $b->refresh();
        $this->assertTrue($b->status === 'cancelled' && (float) $b->cancel_fee === 360.0, 'Annulation depuis le suivi : 360 dus');
        $this->get('/fr/reservation/'.$b->manage_token)->assertOk()->assertSee('Frais d’annulation : 360 MAD');

        $this->postJson('/api/v1/spas/'.$this->spa->slug.'/quote', ['treatment' => $this->m->id, 'party' => 1, 'rate' => 'nr'])->assertOk()->assertJsonPath('data.total', 360)->assertJsonPath('data.rate', 'non_refundable');
        $this->getJson('/api/v1/bookings/'.$b->manage_token)->assertOk()->assertJsonPath('data.conditions.rate', 'non_refundable')->assertJsonPath('data.conditions.cancel_fee', 360)->assertJsonPath('data.conditions.cancellation_hours', 72);
    }
}
