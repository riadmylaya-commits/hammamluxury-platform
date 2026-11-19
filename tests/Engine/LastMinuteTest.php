<?php

namespace Tests\Engine;

use App\Domain\Booking\BookingException;
use App\Filament\Partner\Pages\EditSpaProfile;
use App\Filament\Partner\Widgets\PendingBookings;
use App\Filament\Partner\Widgets\StatsOverview;
use App\Livewire\Site\BookingFlow;
use App\Mail\BookingMail;
use App\Models\Allocation;
use App\Models\City;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;

/** Option « dernière minute » : choisie par le partenaire, délai de réponse de 30 min en heures de service, jamais de confirmation automatique. */
class LastMinuteTest extends BookingFlowTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['hl.admin_hours' => [8, 21], 'hl.waiting_ttl_hours' => 2, 'hl.referenced_lead_margin_minutes' => 30,
            'hl.last_minute' => ['response_minutes' => 30, 'margin_minutes' => 60, 'night_requests' => true]]);
    }

    private function t(string $time, int $addDays = 0): CarbonImmutable
    {
        return CarbonImmutable::parse($this->day)->addDays($addDays)->setTimeFromTimeString($time);
    }

    /** @return array{0: ?string, 1: bool} [échéance « J HH:MM » ou null si le créneau n'est pas proposé, dernière minute ?] */
    private function decide(string $requestAt, string $startAt, int $requestDay = 0, int $startDay = 0): array
    {
        $now = $this->t($requestAt, $requestDay);
        $start = $this->t($startAt, $startDay);
        $spa = $this->reload();
        if ($start < $this->bookings->earliestRequestStart($spa, $now)) {
            return [null, false];
        }
        [$until, $lastMinute] = $this->bookings->requestDeadline($spa, $start, $now);
        $this->assertTrue($until->lte($start->subMinutes(30)), "Au moins 30 min entre l’expiration ($until) et le soin ($start)");

        return [($until->isSameDay($this->t('00:00')) ? 'J ' : 'J+1 ').$until->format('H:i'), $lastMinute];
    }

    public function test_option_is_off_by_default_and_the_normal_rule_applies_to_everyone(): void
    {
        $this->assertFalse($this->reload()->acceptsLastMinute(), 'Désactivée par défaut');
        $this->assertSame(['J 12:00', false], $this->decide('10:00', '14:00'), 'Normal : 2 h de réponse');
        $this->assertSame([null, false], $this->decide('10:00', '12:00'), 'Sans l’option : créneau proche non proposé');
        $this->assertSame(['J+1 10:00', false], $this->decide('23:00', '11:00', 0, 1), 'Nuit : le délai démarre à 8 h');
        $this->assertSame([null, false], $this->decide('23:00', '09:30', 0, 1));
    }

    public function test_enabled_option_follows_one_rule_counted_in_service_hours(): void
    {
        $this->spa->update(['last_minute_enabled' => true]);
        $this->assertTrue($this->reload()->acceptsLastMinute());
        $cases = [
            ['10:00', '14:00', 0, 0, ['J 12:00', false], 'Le délai normal reste prioritaire quand il tient'],
            ['10:00', '12:00', 0, 0, ['J 10:30', true], 'Dernière minute : réponse sous 30 min'],
            ['10:00', '11:30', 0, 0, ['J 10:30', true], 'Limite : 1 h 30 avant le soin'],
            ['10:00', '11:29', 0, 0, [null, false], 'Moins d’1 h 30 : non proposé'],
            ['20:20', '22:00', 0, 0, ['J 20:50', true], 'Soir'],
            ['20:30', '22:00', 0, 0, ['J 21:00', true], 'Dernière demande du soir : réponse à 21 h pile'],
            ['20:31', '22:30', 0, 0, [null, false], 'Après 20 h 30 : plus de dernière minute le soir même'],
            ['20:40', '11:00', 0, 1, ['J+1 09:40', false], 'Soir pour le lendemain : délai normal reporté'],
            ['20:40', '09:30', 0, 1, ['J+1 08:10', true], 'Soir pour tôt le lendemain : 20 min le soir + 10 min dès 8 h'],
            ['23:00', '09:30', 0, 1, ['J+1 08:30', true], 'Nuit : le compteur de 30 min démarre à 8 h'],
            ['23:00', '09:29', 0, 1, [null, false], 'Nuit : moins d’1 h après l’échéance'],
            ['07:50', '09:30', 0, 0, ['J 08:30', true], 'Avant 8 h : même règle que la nuit'],
            ['08:00', '09:30', 0, 0, ['J 08:30', true], 'À 8 h'],
        ];
        foreach ($cases as [$at, $start, $d1, $d2, $expected, $label]) {
            $this->assertSame($expected, $this->decide($at, $start, $d1, $d2), "$label (demande $at, soin $start)");
        }
    }

    public function test_strict_setting_limits_last_minute_to_requests_made_in_service_hours(): void
    {
        config(['hl.last_minute.night_requests' => false]);
        $this->spa->update(['last_minute_enabled' => true]);
        $this->assertSame(['J 10:30', true], $this->decide('10:00', '12:00'));
        $this->assertSame(['J 21:00', true], $this->decide('20:30', '22:00'));
        $this->assertSame([null, false], $this->decide('07:50', '09:30'), 'Avant 8 h : refusé en mode strict');
        $this->assertSame([null, false], $this->decide('23:00', '09:30', 0, 1), 'Nuit : refusé en mode strict');
        $this->assertSame([null, false], $this->decide('20:40', '09:30', 0, 1));
    }

    public function test_option_never_applies_when_withdrawn_or_when_hammamluxury_manages_the_listing(): void
    {
        $this->spa->update(['last_minute_enabled' => true, 'last_minute_allowed' => false]);
        $this->assertFalse($this->reload()->acceptsLastMinute(), 'Retirée par l’Admin');
        $this->assertSame([null, false], $this->decide('10:00', '12:00'));

        $this->spa->update(['last_minute_allowed' => true, 'managed_by' => 'admin']);
        $this->assertFalse($this->reload()->acceptsLastMinute(), 'Fiche gérée par HammamLuxury');

        $this->spa->update(['managed_by' => 'partner', 'listing_type' => 'referenced']);
        $this->assertFalse($this->reload()->acceptsLastMinute(), 'Fiche référencée');
        $this->assertSame([null, false], $this->decide('10:00', '12:00'));
    }

    public function test_last_minute_request_waits_for_the_partner_then_expires_once_and_releases_the_slot(): void
    {
        $this->spa->update(['last_minute_enabled' => true]);
        $this->reload();
        $this->travelTo($this->t('10:00'));
        try {
            $this->intent('11:00', ['treatment' => $this->h->id, 'party' => 1]);
            $this->fail('Soin à moins d’1 h 30 : refusé');
        } catch (BookingException $e) {
            $this->assertSame('too_soon', $e->reason);
        }
        $b = $this->submit($this->intent('12:00', ['treatment' => $this->h->id, 'party' => 1]))['booking'];
        $this->assertSame('waiting', $b->status, 'Jamais confirmée automatiquement');
        $this->assertTrue($b->last_minute);
        $this->assertSame('10:30', $b->expires_at->format('H:i'));

        $partnerMail = new BookingMail($b, 'created', 'partner');
        $this->assertStringStartsWith('Dernière minute', $partnerMail->envelope()->subject);
        $this->assertStringContainsString('30 minutes', $partnerMail->render());
        $this->assertStringContainsString('délai de 30 minutes', $partnerMail->render());
        $this->assertStringNotContainsString('délai de 2 h', $partnerMail->render(), 'E-mail partenaire : pas de délai de 2 h pour une dernière minute');
        $client = (new BookingMail($b, 'created', 'client'))->render();
        $this->assertStringContainsString('10h30', $client, 'E-mail client : heure limite');
        $this->assertStringContainsString('dernière minute', $client);

        $this->travelTo($this->t('10:29'));
        $this->assertSame([], $this->bookings->expireWaiting(), 'Pas d’expiration avant l’échéance');
        $this->assertSame('waiting', $b->fresh()->status);
        $this->travelTo($this->t('10:30'));
        $this->assertSame([$b->id], $this->bookings->expireWaiting());
        $this->assertSame([], $this->bookings->expireWaiting(), 'Une seule expiration');
        $this->assertSame('expired', $b->fresh()->status);
        $this->assertSame(0, Allocation::where('booking_id', $b->id)->where('status', 'active')->count(), 'Créneau libéré');
        try {
            $this->bookings->accept($b->fresh());
            $this->fail('Une réponse après l’expiration est refusée');
        } catch (BookingException $e) {
            $this->assertNotSame('confirmed', $b->fresh()->status);
        }
    }

    public function test_partner_acceptance_confirms_a_last_minute_request_and_it_no_longer_expires(): void
    {
        $this->spa->update(['last_minute_enabled' => true]);
        $this->reload();
        $this->travelTo($this->t('10:00'));
        $b = $this->submit($this->intent('12:00', ['treatment' => $this->h->id, 'party' => 1]))['booking'];
        $this->travelTo($this->t('10:20'));
        $this->bookings->accept($b->fresh());
        $this->travelTo($this->t('11:00'));
        $this->assertSame([], $this->bookings->expireWaiting());
        $this->assertSame('confirmed', $b->fresh()->status);
    }

    public function test_boundary_request_with_seconds_keeps_the_shortened_deadline(): void
    {
        $this->spa->update(['last_minute_enabled' => true]);
        $this->reload();
        $this->travelTo($this->t('10:00:20'));
        $b = $this->submit($this->intent('11:30', ['treatment' => $this->h->id, 'party' => 1]))['booking'];
        $this->assertTrue($b->last_minute, 'Demande à 10 h 00 min 20 s pour 11 h 30 : dernière minute');
        $this->assertSame('10:30', $b->expires_at->format('H:i'));
    }

    public function test_slot_is_rechecked_when_the_customer_sends_late(): void
    {
        $this->spa->update(['last_minute_enabled' => true]);
        $this->reload();
        $this->travelTo($this->t('10:25'));
        $prepared = $this->intent('12:00', ['treatment' => $this->h->id, 'party' => 1]);
        $this->travelTo($this->t('10:35'));
        $r = $this->submit($prepared);
        $this->assertFalse($r['ok'], 'À 10 h 35, le soin de 12 h est à moins d’1 h 30');
        $this->assertSame('too_soon', $r['reason']);
    }

    public function test_normal_request_is_not_flagged_and_customer_mail_has_no_last_minute_line(): void
    {
        $this->spa->update(['last_minute_enabled' => true]);
        $this->reload();
        $this->travelTo($this->t('09:00'));
        $b = $this->submit($this->intent('17:00', ['treatment' => $this->h->id, 'party' => 1]))['booking'];
        $this->assertFalse($b->last_minute);
        $this->assertSame('11:00', $b->expires_at->format('H:i'));
        $this->assertStringNotContainsString('dernière minute', (new BookingMail($b, 'created', 'client'))->render());
    }

    public function test_partner_can_turn_the_option_on_from_the_profile(): void
    {
        $this->spa->update(['city_id' => City::first()?->id ?? City::create(['slug' => 'marrakech', 'name_fr' => 'Marrakech', 'name_en' => 'Marrakech'])->id]);
        $this->actingAs($this->spa->partner->user);
        Filament::setCurrentPanel(Filament::getPanel('partner'));
        Filament::setTenant($this->spa, true);
        Livewire::test(EditSpaProfile::class)->assertSee('Accepter les demandes de dernière minute')
            ->fillForm(['last_minute_enabled' => true])->call('save')->assertHasNoFormErrors();
        $this->assertTrue($this->reload()->acceptsLastMinute(), 'Le partenaire active lui-même l’option');
    }

    public function test_partner_dashboard_shows_the_last_minute_deadline_first(): void
    {
        $this->spa->update(['last_minute_enabled' => true, 'city_id' => City::first()?->id ?? City::create(['slug' => 'marrakech', 'name_fr' => 'Marrakech', 'name_en' => 'Marrakech'])->id]);
        $this->reload();
        $this->travelTo($this->t('10:00'));
        $this->submit($this->intent('12:00', ['treatment' => $this->h->id, 'party' => 1]));
        $this->actingAs($this->spa->partner->user);
        Filament::setCurrentPanel(Filament::getPanel('partner'));
        Filament::setTenant($this->spa, true);
        Livewire::test(StatsOverview::class)->assertSee('Dernière minute : répondre avant 10h30')->assertDontSee('Réponse attendue sous');
        Livewire::test(PendingBookings::class)->assertSee('Dernière minute · avant 10h30')->assertSee('Répondre')
            ->assertTableActionExists('accept')->assertTableActionExists('view');
    }

    public function test_customer_sees_the_shortened_deadline_before_sending(): void
    {
        URL::defaults(['locale' => 'fr']);
        app()->setLocale('fr');
        $this->spa->update(['last_minute_enabled' => true]);
        $this->reload();
        $this->travelTo($this->t('10:00'));
        Livewire::test(BookingFlow::class, ['spa' => $this->spa])
            ->call('selectTreatment', $this->h->id)->call('next')->call('pickDate', $this->day)
            ->call('pickTime', '17:00')->assertDontSee('Dernière minute')
            ->call('pickTime', '12:00')->assertSee('Dernière minute')->assertSee('sous 30 minutes')
            ->call('next')->assertSet('step', 3)->assertSee('au plus tard à 10h30');
    }
}
