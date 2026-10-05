<?php

namespace Tests\Engine;

use App\Domain\Booking\BookingException;
use App\Domain\Booking\BookingService;
use App\Domain\Booking\CapacityEngine;
use App\Domain\Catalogue\CapacityReadiness;
use App\Domain\Partner\OnboardingService;
use App\Filament\Partner\Pages\OfflineBooking;
use App\Livewire\Site\BookingFlow;
use App\Models\City;
use App\Models\Partner;
use App\Models\Spa;
use App\Models\SpaHour;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\ReferenceSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;
use Tests\TestCase;

/** Lot 3 — réservations saisies par le partenaire (même moteur, même verrou), configuration complète, réservation instantanée. */
class OfflineBookingTest extends TestCase
{
    use RefreshDatabase;

    private Spa $spa;

    private User $owner;

    private BookingService $bookings;

    private CarbonImmutable $day;

    protected function setUp(): void
    {
        parent::setUp();
        URL::defaults(['locale' => 'fr']);
        app()->setLocale('fr');
        Mail::fake();
        $this->seed(ReferenceSeeder::class);
        $this->owner = User::create(['first_name' => 'Nadia', 'last_name' => 'Benali', 'email' => 'nadia@example.test', 'password' => 'secret-test', 'role' => 'partner', 'phone' => '+212661351989', 'email_verified_at' => now()]);
        $partner = Partner::create(['user_id' => $this->owner->id, 'company_name' => 'Nadia SARL', 'status' => 'approved']);
        $svc = app(OnboardingService::class);
        $this->spa = $svc->saveSpa($partner, null, ['name' => 'Hammam Lot 3', 'category' => 'hammam', 'city_id' => City::query()->value('id'), 'address' => '1 rue Test', 'description_fr' => 'Test', 'phone' => '+212524000000']);
        $svc->saveTreatments($this->spa, [
            ['name_fr' => 'Massage relaxant', 'category' => 'massage', 'duration_min' => 60, 'price_solo' => 400],
            ['name_fr' => 'Hammam simple', 'category' => 'hammam', 'duration_min' => 45, 'price_solo' => 150],
        ]);
        // 2 cabines mais 1 seule praticienne : 1 massage à la fois.
        $svc->provisionResources($this->spa, ['hammam_capacity' => 4, 'massage_cabins' => 2, 'treatment_rooms' => 0, 'therapists' => 1, 'cabin_buffer_min' => 0]);
        foreach (range(0, 6) as $wd) {
            SpaHour::create(['spa_id' => $this->spa->id, 'weekday' => $wd, 'opens_min' => 9 * 60, 'closes_min' => 20 * 60]);
        }
        $this->spa->update(['status' => 'published']);
        $this->spa = $this->spa->fresh();
        $this->bookings = app(BookingService::class);
        $this->day = CarbonImmutable::now()->addDays(3)->startOfDay();
    }

    private function massageId(): int
    {
        return $this->spa->treatments()->where('slug', 'massage-relaxant')->value('id');
    }

    private function customer(): array
    {
        return ['first_name' => 'Karim', 'last_name' => 'Tazi', 'phone' => '+212661000000', 'email' => ''];
    }

    public function test_offline_booking_is_confirmed_consumes_capacity_and_blocks_online_slot(): void
    {
        $start = $this->day->setTime(10, 0);
        $b = $this->bookings->bookOffline($this->spa, $start, [['treatment' => $this->massageId(), 'party' => 1]], $this->customer(), 'whatsapp', $this->owner->id);

        $this->assertSame('confirmed', $b->status);
        $this->assertSame('partner', $b->source);
        $this->assertSame('whatsapp', $b->channel);
        $this->assertSame($this->owner->id, $b->created_by_user_id);
        $this->assertNull($b->expires_at);
        $this->assertSame(2, $b->allocations()->count(), 'cabine + praticienne');
        $this->assertSame([0.0, 0.0, 0.0], [(float) $b->commission_pct, (float) $b->commission_amount, (float) $b->commissionable_amount], 'aucune commission sur une réservation reçue par le partenaire');
        $this->assertSame(400.0, (float) $b->total);
        $this->assertTrue($b->events()->where('type', 'created')->where('actor', 'partner')->exists());
        Mail::assertNothingSent();

        $items = [['treatment' => $this->massageId(), 'party' => 1, 'extras' => []]];
        $times = app(CapacityEngine::class)->availability($this->spa, $this->day->toDateString(), $items, null, $this->day)['times'];
        $this->assertNotContains('10:00', $times);
        $this->assertNotContains('10:30', $times);
        $this->assertContains('11:00', $times);
        $this->assertContains('09:00', $times);

        try {
            $this->bookings->book($this->spa, $start->addMinutes(30), $items, $this->customer() + ['email' => 'c@example.test']);
            $this->fail('La réservation en ligne aurait dû être refusée (praticienne occupée).');
        } catch (BookingException $e) {
            $this->assertSame('unavailable', $e->reason);
        }
    }

    public function test_offline_booking_refused_when_capacity_is_gone_or_outside_hours_or_in_the_past(): void
    {
        $items = [['treatment' => $this->massageId(), 'party' => 1]];
        $this->bookings->book($this->spa, $this->day->setTime(14, 0), $items, $this->customer() + ['email' => 'c@example.test'], 'waiting');

        foreach ([[$this->day->setTime(14, 30), 'unavailable'], [$this->day->setTime(19, 30), 'unavailable'], [CarbonImmutable::now()->subDay(), 'too_soon']] as [$start, $reason]) {
            try {
                $this->bookings->bookOffline($this->spa, $start, $items, $this->customer(), 'phone');
                $this->fail("Aurait dû refuser ($reason)");
            } catch (BookingException $e) {
                $this->assertSame($reason, $e->reason);
            }
        }
        $this->expectException(BookingException::class);
        $this->bookings->bookOffline($this->spa, $this->day->setTime(11, 0), $items, $this->customer(), 'pigeon');
    }

    public function test_partner_page_lists_only_free_times_and_records_the_booking(): void
    {
        $this->bookings->bookOffline($this->spa, $this->day->setTime(10, 0), [['treatment' => $this->massageId(), 'party' => 1]], $this->customer(), 'phone');
        $this->actingAs($this->owner);
        Filament::setCurrentPanel(Filament::getPanel('partner'));
        Filament::setTenant($this->spa, true);

        $this->get('/partenaire/'.$this->spa->slug.'/offline-booking')->assertOk()->assertSee(__('partner.offline_title'));

        $t = Livewire::test(OfflineBooking::class)
            ->fillForm(['treatment_id' => $this->massageId(), 'party' => 1, 'date' => $this->day->toDateString(), 'first_name' => 'Sara', 'last_name' => 'Idrissi', 'phone_country' => 'MA', 'phone' => '0661351989', 'channel' => 'walk_in', 'note' => 'Paiement en espèces à l’arrivée']);
        $t->fillForm(['time' => '10:00'])->call('save');
        $this->assertFalse($this->spa->bookings()->where('first_name', 'Sara')->exists(), 'heure déjà prise : refusée par le moteur');

        $t->fillForm(['time' => '11:00'])->call('save')->assertHasNoFormErrors();
        $b = $this->spa->bookings()->where('first_name', 'Sara')->firstOrFail();
        $this->assertSame(['confirmed', 'partner', 'walk_in', '+212661351989'], [$b->status, $b->source, $b->channel, $b->phone]);
        $this->assertSame('11:00', $b->start_at->format('H:i'));
        $this->assertNull($b->note, 'la note partenaire ne doit pas être traitée comme une note client');
        $this->assertSame('Paiement en espèces à l’arrivée', $b->notes()->value('body'));
        $this->assertSame($this->owner->id, $b->notes()->value('user_id'));
    }

    public function test_readiness_requires_enough_therapists_and_hours(): void
    {
        $this->assertTrue(CapacityReadiness::passes($this->spa), implode(' | ', CapacityReadiness::failures($this->spa)));

        app(OnboardingService::class)->saveTreatments($this->spa, [['name_fr' => 'Massage 4 mains', 'category' => 'massage', 'duration_min' => 60, 'price_solo' => 800, 'staff_per_person' => 2]]);
        $this->assertFalse(CapacityReadiness::passes($this->spa->fresh()), '1 praticienne pour un soin à 2 praticiens');

        app(OnboardingService::class)->provisionResources($this->spa, ['therapists' => 2]);
        $this->assertTrue(CapacityReadiness::passes($this->spa->fresh()));

        $this->spa->hours()->delete();
        $this->assertFalse(CapacityReadiness::passes($this->spa->fresh()));
    }

    public function test_instant_booking_confirms_online_booking_only_while_configuration_is_complete(): void
    {
        $this->spa->update(['instant_booking' => true, 'instant_booking_at' => now()]);
        $spa = $this->spa->fresh();
        $this->assertTrue($spa->instantBookingActive());

        $book = function (Spa $spa, string $time, bool $instant) {
            $t = Livewire::test(BookingFlow::class, ['spa' => $spa])
                ->call('selectTreatment', $this->massageId())->call('next')
                ->set('date', $this->day->toDateString())->set('time', $time)->call('next')->assertSet('step', 3);
            $instant ? $t->assertSee(__('ui.instant_title')) : $t->assertDontSee(__('ui.instant_title'));
            $t->set('first_name', 'Lina')->set('last_name', 'Zahra')->set('email', 'lina@example.test')->set('phoneCountry', 'MA')->set('phone', '0661351989')->set('terms', true)
                ->call('submit')->assertHasNoErrors();
        };

        $book($spa, '09:00', true);
        $b1 = $spa->bookings()->latest('id')->firstOrFail();
        $this->assertSame(['confirmed', 'online'], [$b1->status, $b1->source]);
        $this->assertNotNull($b1->confirmed_at);
        $this->assertTrue($b1->events()->where('type', 'notified:confirmed')->exists());
        $this->assertFalse($b1->events()->where('type', 'notified:created')->exists());
        $this->assertTrue($b1->instantConfirmed(), 'suivi client : « Réservation confirmée », pas « Demande envoyée »');
        $this->get(route('booking.show', ['locale' => 'fr', 'token' => $b1->manage_token]))->assertOk()->assertSee(__('ui.timeline_instant.confirm'))->assertDontSee(__('ui.timeline.confirm'));

        // Configuration redevenue incomplète (horaires supprimés puis un seul jour rétabli sans praticienne suffisante) → retour au circuit demande.
        app(OnboardingService::class)->saveTreatments($spa, [
            ['name_fr' => 'Massage relaxant', 'category' => 'massage', 'duration_min' => 60, 'price_solo' => 400],
            ['name_fr' => 'Massage 4 mains', 'category' => 'massage', 'duration_min' => 60, 'price_solo' => 800, 'staff_per_person' => 2],
        ]);
        $spa = $spa->fresh();
        $this->assertTrue($spa->instant_booking);
        $this->assertFalse($spa->instantBookingActive());
        $book($spa, '12:00', false);
        $b2 = $spa->bookings()->latest('id')->firstOrFail();
        $this->assertSame('waiting', $b2->status);
        $this->assertFalse($b2->instantConfirmed());
        $b2 = $spa->bookings()->latest('id')->firstOrFail();
        $this->assertSame('waiting', $b2->status);
        $this->assertNotNull($b2->expires_at);
    }

    public function test_online_and_offline_compete_for_the_same_last_place(): void
    {
        $items = [['treatment' => $this->massageId(), 'party' => 1, 'extras' => []]];
        $online = $this->bookings->book($this->spa, $this->day->setTime(16, 0), $items, $this->customer() + ['email' => 'c@example.test'], 'waiting');
        $this->assertSame('waiting', $online->status, 'une demande en attente réserve déjà la place');
        try {
            $this->bookings->bookOffline($this->spa, $this->day->setTime(16, 0), $items, $this->customer(), 'phone');
            $this->fail('La saisie partenaire ne doit pas doubler une demande en attente.');
        } catch (BookingException $e) {
            $this->assertSame('unavailable', $e->reason);
        }
        $this->bookings->decline($online);
        $offline = $this->bookings->bookOffline($this->spa, $this->day->setTime(16, 0), $items, $this->customer(), 'phone');
        $this->assertSame('confirmed', $offline->status);
    }
}
