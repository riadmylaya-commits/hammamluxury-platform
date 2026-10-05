<?php

namespace Tests\Engine;

use App\Domain\Booking\BookingException;
use App\Domain\Booking\BookingService;
use App\Domain\Booking\CapacityEngine;
use App\Domain\Partner\OnboardingService;
use App\Filament\Admin\Resources\BookingDeclineResource\Pages\ListBookingDeclines;
use App\Filament\Partner\Resources\BlockResource\Pages\CreateBlock;
use App\Filament\Partner\Resources\BookingResource\Pages\ListBookings;
use App\Models\Allocation;
use App\Models\Block;
use App\Models\BookingDecline;
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

/** Lot 4 — refus structuré d'une demande : motif obligatoire, libération du créneau, traçabilité Admin, suivi « plus de place ». */
class BookingDeclineTest extends TestCase
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
        $this->spa = $svc->saveSpa($partner, null, ['name' => 'Hammam Lot 4', 'category' => 'hammam', 'city_id' => City::query()->value('id'), 'address' => '1 rue Test', 'description_fr' => 'Test', 'phone' => '+212524000000']);
        $svc->saveTreatments($this->spa, [
            ['name_fr' => 'Massage relaxant', 'category' => 'massage', 'duration_min' => 60, 'price_solo' => 400],
        ]);
        // 1 seule praticienne : 1 massage à la fois.
        $svc->provisionResources($this->spa, ['hammam_capacity' => 4, 'massage_cabins' => 2, 'treatment_rooms' => 0, 'therapists' => 1, 'cabin_buffer_min' => 0]);
        foreach (range(0, 6) as $wd) {
            SpaHour::create(['spa_id' => $this->spa->id, 'weekday' => $wd, 'opens_min' => 9 * 60, 'closes_min' => 20 * 60]);
        }
        $this->spa->update(['status' => 'published']);
        $this->spa = $this->spa->fresh();
        $this->bookings = app(BookingService::class);
        $this->day = CarbonImmutable::now()->addDays(3)->startOfDay();
    }

    private function request(int $hour = 10)
    {
        $treatment = $this->spa->treatments()->value('id');

        return $this->bookings->book($this->spa, $this->day->setTime($hour, 0), [['treatment' => $treatment, 'party' => 1]],
            ['first_name' => 'Karim', 'last_name' => 'Tazi', 'phone' => '+212661000000', 'email' => 'karim@example.test']);
    }

    public function test_decline_requires_a_reason_and_a_note_for_other(): void
    {
        $b = $this->request();
        $this->assertSame('waiting', $b->status);

        try {
            $this->bookings->decline($b, 'partner');
            $this->fail('motif obligatoire');
        } catch (BookingException $e) {
            $this->assertSame(__('booking.reason_required'), $e->getMessage());
        }
        try {
            $this->bookings->decline($b, 'partner', 'other', '  ');
            $this->fail('« Autre » exige une explication');
        } catch (BookingException $e) {
            $this->assertSame(__('booking.note_required_other'), $e->getMessage());
        }
        try {
            $this->bookings->decline($b, 'partner', 'bogus');
            $this->fail('motif inconnu refusé');
        } catch (BookingException $e) {
            $this->assertSame(__('booking.reason_required'), $e->getMessage());
        }

        $this->assertSame('waiting', $b->fresh()->status, 'un refus invalide ne change rien');
        $this->assertSame(0, BookingDecline::count());
    }

    public function test_decline_releases_the_slot_and_is_traceable(): void
    {
        $b = $this->request();
        $this->assertGreaterThan(0, Allocation::where('booking_id', $b->id)->where('status', 'active')->count());

        $this->actingAs($this->owner);
        $declined = $this->bookings->decline($b, 'partner', 'closed', 'Fermeture pour travaux', $this->owner->id);

        $this->assertSame('declined', $declined->status);
        $this->assertNull($declined->expires_at);
        $this->assertSame(0, Allocation::where('booking_id', $b->id)->where('status', 'active')->count(), 'créneau libéré');

        $d = BookingDecline::firstOrFail();
        $this->assertSame([$b->id, $this->spa->id, $this->owner->id, 'partner', 'closed', 'Fermeture pour travaux', 1],
            [$d->booking_id, $d->spa_id, $d->declined_by, $d->actor, $d->reason, $d->note, $d->party]);
        $this->assertTrue($d->start_at->equalTo($b->start_at));
        $this->assertSame($d->id, $b->fresh()->decline->id);

        $this->assertTrue($b->events()->where('type', 'declined')->exists());
        $event = $b->events()->where('type', 'declined:reason')->firstOrFail();
        $this->assertSame('closed', $event->payload['reason']);
        $this->assertDatabaseHas('activity_logs', ['action' => 'booking.declined', 'user_id' => $this->owner->id]);

        // Le créneau est de nouveau réservable par un autre client.
        $again = $this->request();
        $this->assertSame('waiting', $again->status);

        // Un second refus de la même demande est impossible.
        $this->expectException(BookingException::class);
        $this->bookings->decline($declined, 'partner', 'full');
    }

    public function test_full_decline_records_whether_the_engine_still_had_room(): void
    {
        // Demande seule à 10 h : la praticienne était libre → refus « plus de place » alors que la place existait (planning à vérifier).
        $alone = $this->request(10);
        $this->bookings->decline($alone, 'partner', 'full', null, $this->owner->id);
        $d1 = BookingDecline::where('booking_id', $alone->id)->firstOrFail();
        $this->assertTrue($d1->engine_available);
        $this->assertTrue($d1->isSuspicious());

        // Demande à 15 h, puis le partenaire ferme l'après-midi (blocage) : le moteur n'a réellement plus de place → refus justifié.
        $second = $this->request(15);
        Block::create(['spa_id' => $this->spa->id, 'scope' => 'spa', 'start_at' => $this->day->setTime(14, 0), 'end_at' => $this->day->setTime(18, 0), 'kind' => 'closed']);
        $this->assertFalse(app(CapacityEngine::class)->check($this->spa, $this->day->setTime(15, 0), $second->quote['items'], $second->id)['ok']);

        $this->bookings->decline($second, 'partner', 'full', null, $this->owner->id);
        $d2 = BookingDecline::where('booking_id', $second->id)->firstOrFail();
        $this->assertFalse($d2->engine_available);
        $this->assertFalse($d2->isSuspicious());
        $this->assertSame(0, Allocation::where('booking_id', $second->id)->where('status', 'active')->count());
    }

    public function test_admin_and_partner_panels_show_the_decline(): void
    {
        $b = $this->request();
        $this->bookings->decline($b, 'partner', 'full', 'Groupe déjà prévu', $this->owner->id);

        $this->actingAs($this->owner)->get('/partenaire/'.$this->spa->slug.'/bookings/'.$b->id)->assertOk()
            ->assertSee(__('partner.decline_reasons.full'))->assertSee('Groupe déjà prévu')->assertDontSee(__('admin.decline_engine_yes'));
    }

    public function test_admin_panel_lists_declines_with_engine_verdict(): void
    {
        $b = $this->request();
        $this->bookings->decline($b, 'partner', 'full', 'Groupe déjà prévu', $this->owner->id);
        $admin = User::create(['name' => 'Admin', 'email' => 'admin@example.test', 'password' => 'secret-test', 'role' => 'admin', 'email_verified_at' => now()]);
        $d = BookingDecline::firstOrFail();

        $this->actingAs($admin)->get('/admin/booking-declines')->assertOk()->assertSee($b->reference)->assertSee(__('partner.decline_reasons.full'))->assertSee(__('admin.decline_engine_yes'));
        $this->actingAs($admin)->get('/admin/booking-declines/'.$d->id)->assertOk()->assertSee('Groupe déjà prévu')->assertSee($this->owner->name);
        $this->actingAs($admin)->get('/admin/bookings/'.$b->id)->assertOk()->assertSee(__('partner.decline_reasons.full'))->assertSee('Groupe déjà prévu');
        $this->actingAs($admin)->get('/admin/spas')->assertOk()->assertSee('1 (1)');

        $second = $this->request(15);
        Block::create(['spa_id' => $this->spa->id, 'scope' => 'spa', 'start_at' => $this->day->setTime(14, 0), 'end_at' => $this->day->setTime(18, 0), 'kind' => 'closed']);
        $this->bookings->decline($second, 'partner', 'full', null, $this->owner->id);
        $justified = BookingDecline::where('booking_id', $second->id)->firstOrFail();
        Livewire::test(ListBookingDeclines::class)
            ->assertCanSeeTableRecords([$d, $justified])
            ->filterTable('suspicious')
            ->assertCanSeeTableRecords([$d])
            ->assertCanNotSeeTableRecords([$justified]);
    }

    public function test_client_never_sees_internal_reason_or_note(): void
    {
        $b = $this->request();
        $this->bookings->decline($b, 'partner', 'other', 'Note interne : client déjà venu, impayé', $this->owner->id);

        $this->get(route('booking.show', ['locale' => 'fr', 'token' => $b->manage_token]))
            ->assertOk()
            ->assertSee(__('ui.status.declined'))
            ->assertDontSee('Note interne')
            ->assertDontSee('impayé')
            ->assertDontSee(__('partner.decline_reasons.other'));
    }

    public function test_partner_upcoming_filter_hides_past_bookings(): void
    {
        $future = $this->request(10);
        $past = $this->request(12);
        $past->forceFill(['start_at' => now()->subDays(2), 'end_at' => now()->subDays(2)->addHour()])->save();

        $this->actingAs($this->owner);
        Filament::setCurrentPanel(Filament::getPanel('partner'));
        Filament::setTenant($this->spa, true);

        Livewire::test(ListBookings::class)
            ->assertCanSeeTableRecords([$future, $past])
            ->filterTable('upcoming')
            ->assertCanSeeTableRecords([$future])
            ->assertCanNotSeeTableRecords([$past]);
    }

    public function test_partner_block_form_accepts_a_valid_range_and_rejects_an_inverted_one(): void
    {
        $this->actingAs($this->owner);
        Filament::setCurrentPanel(Filament::getPanel('partner'));
        Filament::setTenant($this->spa, true);

        Livewire::test(CreateBlock::class)
            ->fillForm(['scope' => 'spa', 'kind' => 'closed', 'start_at' => $this->day->setTime(11, 0)->format('Y-m-d H:i'), 'end_at' => $this->day->setTime(10, 0)->format('Y-m-d H:i')])
            ->call('create')->assertHasFormErrors(['end_at']);
        $this->assertSame(0, Block::count());

        Livewire::test(CreateBlock::class)
            ->fillForm(['scope' => 'spa', 'kind' => 'closed', 'start_at' => $this->day->setTime(10, 0)->format('Y-m-d H:i'), 'end_at' => $this->day->setTime(11, 0)->format('Y-m-d H:i')])
            ->call('create')->assertHasNoFormErrors();
        $block = Block::firstOrFail();
        $this->assertSame([$this->day->setTime(10, 0)->format('Y-m-d H:i'), $this->day->setTime(11, 0)->format('Y-m-d H:i')], [$block->start_at->format('Y-m-d H:i'), $block->end_at->format('Y-m-d H:i')]);
        $this->assertFalse(app(CapacityEngine::class)->check($this->spa, $this->day->setTime(10, 0), [['treatment' => $this->spa->treatments()->value('id'), 'party' => 1]])['ok']);
    }
}
