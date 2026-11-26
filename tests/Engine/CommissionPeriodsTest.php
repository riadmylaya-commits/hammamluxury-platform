<?php

namespace Tests\Engine;

use App\Filament\Admin\Resources\ActivityLogResource;
use App\Filament\Admin\Resources\ActivityLogResource\Pages\ListActivityLogs;
use App\Filament\Admin\Resources\PartnerResource;
use App\Filament\Admin\Resources\PartnerResource\Pages\EditPartner;
use App\Filament\Admin\Resources\PartnerResource\RelationManagers\CommissionPeriodsRelationManager as Manager;
use App\Models\ActivityLog;
use App\Models\Booking;
use App\Models\LedgerEntry;
use App\Models\Partner;
use App\Models\User;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

/** Périodes commerciales par partenaire : mois gratuit, taux promotionnel, retour au standard, alerte, historique des taux. */
class CommissionPeriodsTest extends BookingFlowTestCase
{
    private User $admin;

    private Partner $partner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::create(['name' => 'Admin', 'email' => 'admin@example.test', 'password' => 'secret-test', 'role' => 'admin', 'email_verified_at' => now()]);
        $this->partner = $this->spa->partner;
        config(['hl.alert_email' => 'alerte@example.test']);
    }

    protected function tearDown(): void
    {
        $this->travelBack();
        parent::tearDown();
    }

    private function onDay(string $day, string $time = '09:00'): void
    {
        $this->travelTo(CarbonImmutable::parse("$day $time", config('hl.timezone'))->toMutable());
    }

    private function rate(string $day): float
    {
        return $this->partner->fresh()->commissionPct(CarbonImmutable::parse($day, config('hl.timezone')));
    }

    public function test_launch_offer_free_month_then_promo_then_standard_with_inclusive_boundaries(): void
    {
        $this->actingAs($this->admin);
        Manager::applyLaunchOffer($this->partner, '2026-12-01', 1, 6, 10);

        $this->assertSame(15.0, $this->rate('2026-11-30'), 'avant le début : standard');
        $this->assertSame(0.0, $this->rate('2026-12-01'));
        $this->assertSame(0.0, $this->rate('2026-12-31'), 'dernier jour gratuit inclus');
        $this->assertSame(10.0, $this->rate('2027-01-01'));
        $this->assertSame(10.0, $this->rate('2027-06-30'), 'dernier jour promotionnel inclus');
        $this->assertSame(15.0, $this->rate('2027-07-01'), 'retour automatique au standard');
        $this->assertSame(2, ActivityLog::where('action', 'partner.commission_period_created')->where('user_id', $this->admin->id)->count());
    }

    public function test_manual_partner_rate_applies_outside_periods(): void
    {
        $this->partner->update(['commission_pct' => 12]);
        $this->partner->commissionPeriods()->create(['label' => 'Gratuit', 'commission_pct' => 0, 'starts_on' => '2026-12-01', 'ends_on' => '2026-12-31']);
        $this->assertSame(0.0, $this->rate('2026-12-15'));
        $this->assertSame(12.0, $this->rate('2027-01-01'));
    }

    public function test_overlapping_periods_are_refused(): void
    {
        Manager::applyLaunchOffer($this->partner, '2026-12-01', 1, 6, 10);
        foreach ([['2026-12-31', '2027-01-05'], ['2026-11-01', '2026-12-01'], ['2027-06-30', null], ['2026-01-01', null]] as [$from, $to]) {
            try {
                Manager::assertNoOverlap($this->partner, $from, $to);
                $this->fail("chevauchement accepté : $from → ".($to ?? 'sans fin'));
            } catch (ValidationException) {
                $this->addToAssertionCount(1);
            }
        }
        Manager::assertNoOverlap($this->partner, '2027-07-01', null);
        Manager::assertNoOverlap($this->partner, '2026-11-01', '2026-11-30');
        $first = $this->partner->commissionPeriods()->first();
        Manager::assertNoOverlap($this->partner, '2026-12-01', '2026-12-15', $first->id);
        $this->expectException(ValidationException::class);
        Manager::applyLaunchOffer($this->partner, '2027-06-01', 1, 0, 10);
    }

    public function test_bookings_keep_the_rate_in_force_when_created(): void
    {
        $this->onDay(CarbonImmutable::now()->format('Y-m-d'));
        $today = CarbonImmutable::now(config('hl.timezone'));
        $this->partner->commissionPeriods()->create(['label' => 'Gratuit', 'commission_pct' => 0, 'starts_on' => $today->subDays(10)->format('Y-m-d'), 'ends_on' => $today->format('Y-m-d')]);
        $this->partner->commissionPeriods()->create(['label' => 'Promo', 'commission_pct' => 10, 'starts_on' => $today->addDay()->format('Y-m-d'), 'ends_on' => $today->addDays(2)->format('Y-m-d')]);
        $this->spa = $this->reload();

        $free = $this->book('gratuit', '10:00', [['massage', 1]], true);
        $this->assertSame([0.0, 0.0], [(float) $free->commission_pct, (float) $free->commission_amount]);

        $this->travelTo($today->addDay()->setTime(0, 5)->toMutable());
        $this->spa = $this->reload();
        $promo = $this->book('promo', '12:00', [['massage', 1]], true);
        $this->assertSame([10.0, 40.0], [(float) $promo->commission_pct, (float) $promo->commission_amount]);

        $this->travelTo($today->addDays(3)->setTime(0, 5)->toMutable());
        $this->spa = $this->reload();
        $std = $this->book('standard', '17:00', [['massage', 1]], true);
        $this->assertSame([15.0, 60.0], [(float) $std->commission_pct, (float) $std->commission_amount]);

        $this->partner->commissionPeriods()->delete();
        $this->partner->update(['commission_pct' => 20]);
        $this->assertSame([0.0, 10.0, 15.0], [(float) $free->fresh()->commission_pct, (float) $promo->fresh()->commission_pct, (float) $std->fresh()->commission_pct], 'historique des taux conservé');
    }

    public function test_referenced_listing_stays_at_zero_and_partners_are_isolated(): void
    {
        $this->onDay(CarbonImmutable::now()->format('Y-m-d'));
        $today = CarbonImmutable::now(config('hl.timezone'))->format('Y-m-d');
        $otherUser = User::create(['name' => 'Autre', 'email' => 'autre@example.test', 'password' => 'secret-test', 'role' => 'partner', 'email_verified_at' => now()]);
        $other = Partner::create(['user_id' => $otherUser->id, 'company_name' => 'Autre SARL', 'status' => 'approved']);
        $other->commissionPeriods()->create(['label' => 'Promo', 'commission_pct' => 10, 'starts_on' => $today]);
        $this->assertSame(10.0, $other->fresh()->commissionPct());
        $this->assertSame(15.0, $this->partner->fresh()->commissionPct(), 'la période d’un partenaire ne touche pas un autre');
        $this->assertFalse($other->fresh()->isCommercial(), 'sans fiche sous accord : pas partenaire commercial');
        $this->assertTrue($this->partner->fresh()->isCommercial());

        $this->partner->commissionPeriods()->create(['label' => 'Promo', 'commission_pct' => 10, 'starts_on' => $today]);
        $this->spa->update(['listing_type' => 'referenced', 'managed_by' => 'admin', 'cancellation_hours' => null]);
        $this->spa->resources()->delete();
        $this->spa = $this->reload();
        $this->assertFalse($this->partner->fresh()->isCommercial());
        $b = $this->book('référencé', '10:00', [[$this->hm->slug, 2]], true, 'waiting');
        $this->assertSame(0.0, (float) $b->commission_pct, 'fiche référencée : 0 % même avec une période à 10 %');
    }

    public function test_alert_is_sent_once_before_each_automatic_change(): void
    {
        Mail::fake();
        Manager::applyLaunchOffer($this->partner, '2026-12-01', 1, 6, 10);

        $this->spa->update(['listing_type' => 'referenced']);
        $this->onDay('2026-12-25');
        $this->artisan('hl:commission-alerts')->assertSuccessful();
        $this->assertSame(0, ActivityLog::where('action', 'partner.commission_change_alerted')->count(), 'fiche référencée : le taux reste 0 %, aucune alerte');
        $this->spa->update(['listing_type' => 'partner']);

        $alerts = fn () => ActivityLog::where('action', 'partner.commission_change_alerted')->orderBy('id')->get()
            ->map(fn (ActivityLog $l) => $l->properties['stage'].' '.$l->properties['on'].' '.(float) $l->properties['from'].'>'.(float) $l->properties['to'])->all();

        $this->onDay('2026-12-20');
        $this->artisan('hl:commission-alerts')->assertSuccessful();
        $this->assertSame([], $alerts(), 'plus de 7 jours avant : rien');

        foreach (['2026-12-25', '2026-12-26', '2026-12-30'] as $d) {
            $this->onDay($d);
            $this->artisan('hl:commission-alerts')->assertSuccessful();
            $this->artisan('hl:commission-alerts')->assertSuccessful();
        }
        $this->assertSame(['J-7 2027-01-01 0>10'], $alerts(), 'J-7 une seule fois, rien entre J-7 et J-1');

        $this->onDay('2026-12-31');
        $this->artisan('hl:commission-alerts')->assertSuccessful();
        $this->artisan('hl:commission-alerts')->assertSuccessful();
        $this->assertSame(['J-7 2027-01-01 0>10', 'J-1 2027-01-01 0>10'], $alerts(), 'J-1 une seule fois');

        // Prolongation décidée après les alertes : le changement est déplacé, il est de nouveau annoncé à sa nouvelle date.
        Manager::extend($this->partner->commissionPeriods()->first(), '2027-01-10');
        $this->onDay('2027-01-04');
        $this->artisan('hl:commission-alerts')->assertSuccessful();
        $this->assertSame('J-7 2027-01-11 0>10', $alerts()[2]);

        $this->onDay('2027-07-10');
        $this->artisan('hl:commission-alerts')->assertSuccessful();
        $this->assertSame('J-1 2027-07-11 10>15', last($alerts()), 'commande lancée seulement la veille : J-1 seul');
        $this->assertCount(4, $alerts());
    }

    public function test_exceptional_extension_shifts_the_next_period_and_is_logged(): void
    {
        $this->actingAs($this->admin);
        Manager::applyLaunchOffer($this->partner, '2026-12-01', 1, 6, 10);
        [$free, $promo] = $this->partner->commissionPeriods()->get()->all();

        Manager::extend($free, '2027-01-15', 'Travaux');
        $this->assertSame(0.0, $this->rate('2027-01-15'), 'gratuité prolongée');
        $this->assertSame(10.0, $this->rate('2027-01-16'), 'la période suivante commence le lendemain');
        $this->assertSame(10.0, $this->rate('2027-07-15'), 'les six mois promotionnels sont conservés en entier');
        $this->assertSame(15.0, $this->rate('2027-07-16'));
        $shift = ActivityLog::where('action', 'partner.commission_period_updated')->sole();
        $this->assertSame(['2027-01-01', '2027-06-30', '2027-01-16', '2027-07-15'], [$shift->properties['previous_from'], $shift->properties['previous_to'], $shift->properties['from'], $shift->properties['to']]);
        $text = ActivityLogResource::details($shift);
        $this->assertStringContainsString('Début : 01/01/2027 → 16/01/2027', $text);
        $this->assertStringContainsString('Fin : 30/06/2027 → 15/07/2027', $text);
        $log = ActivityLog::where('action', 'partner.commission_period_extended')->sole();
        $this->assertSame(['2026-12-31', 'Travaux', $this->admin->id], [$log->properties['previous_end'], $log->properties['note'], $log->user_id]);

        Manager::extend($promo->fresh(), '2027-09-30');
        $this->assertSame(10.0, $this->rate('2027-09-30'));
        $this->assertSame(15.0, $this->rate('2027-10-01'));

        foreach (['2027-01-15', '2027-01-01'] as $bad) {
            try {
                Manager::extend($free->fresh(), $bad);
                $this->fail("prolongation acceptée : $bad");
            } catch (ValidationException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->assertSame('2027-01-15', $free->fresh()->ends_on->format('Y-m-d'), 'refus : rien n’est modifié');
    }

    public function test_extension_can_keep_the_original_end_of_the_next_period(): void
    {
        $this->actingAs($this->admin);
        Manager::applyLaunchOffer($this->partner, '2026-12-01', 1, 6, 10);
        $free = $this->partner->commissionPeriods()->orderBy('starts_on')->first();

        Manager::extend($free, '2027-01-15', null, true);
        $this->assertSame(0.0, $this->rate('2027-01-15'));
        $this->assertSame(10.0, $this->rate('2027-01-16'));
        $this->assertSame(10.0, $this->rate('2027-06-30'), 'fin initiale conservée');
        $this->assertSame(15.0, $this->rate('2027-07-01'));
    }

    public function test_shifted_promo_keeps_six_calendar_months(): void
    {
        $this->actingAs($this->admin);
        Manager::applyLaunchOffer($this->partner, '2026-11-26', 1, 6, 10);
        Manager::extend($this->partner->commissionPeriods()->orderBy('starts_on')->first(), '2027-01-09');
        $promo = $this->partner->commissionPeriods()->orderBy('starts_on')->get()->last();
        $this->assertSame(['2027-01-10', '2027-07-09'], [$promo->starts_on->format('Y-m-d'), $promo->ends_on->format('Y-m-d')]);
    }

    public function test_every_logged_action_has_a_french_label(): void
    {
        $labels = __('admin.log_action', [], 'fr');
        $missing = [];
        foreach (File::allFiles(app_path()) as $file) {
            $code = $file->getContents();
            if (! str_contains($code, 'ActivityLog::record') && ! str_contains($code, "=> ['auth.")) {
                continue;
            }
            preg_match_all("/(?<!__\\(|route\\(|config\\(|trans\\()'((?:auth|billing|booking|partner|spa|review|extra|notification)\\.[a-z0-9_]+)'/", $code, $found);
            foreach ($found[1] as $action) {
                if (! isset($labels[$action]) && ! str_ends_with($action, '_') && $action !== 'booking.spa') {
                    $missing[] = $action;
                }
            }
        }
        foreach (['published', 'refused', 'suspended', 'changes_requested'] as $decision) {
            $this->assertArrayHasKey("spa.$decision", $labels);
        }
        $this->assertSame([], array_values(array_unique($missing)));
    }

    public function test_cancelled_absent_and_completed_bookings_use_the_rate_frozen_at_creation(): void
    {
        $today = CarbonImmutable::now(config('hl.timezone'));
        $this->partner->commissionPeriods()->create(['label' => 'Promo', 'commission_pct' => 10, 'starts_on' => $today->subDay()->format('Y-m-d'), 'ends_on' => $today->addDay()->format('Y-m-d')]);
        $this->spa = $this->reload();
        $done = $this->book('réalisée', '10:00', [['massage', 1]], true);
        $absent = $this->book('absent', '12:00', [['massage', 1]], true);
        $cancelled = $this->book('annulée', '17:00', [['massage', 1]], true);
        $this->assertSame([10.0, 10.0, 10.0], [(float) $done->commission_pct, (float) $absent->commission_pct, (float) $cancelled->commission_pct]);

        // Le taux passe à 15 % (fin de période), puis l'administration le modifie encore à la main : sans effet sur ces réservations.
        $this->bookings->cancel($cancelled, 'admin');
        $this->travelTo($absent->end_at->addHours(1)->toMutable());
        $this->partner->update(['commission_pct' => 30]);
        $this->assertSame(30.0, $this->partner->fresh()->commissionPct());
        $this->bookings->complete($done->fresh(), 'admin');
        $this->bookings->noShow($absent->fresh(), 'admin', true);

        $done = $done->fresh();
        $absent = $absent->fresh();
        $cancelled = $cancelled->fresh();
        $this->assertSame(['completed', 10.0, 40.0, 40.0], [$done->status, (float) $done->commission_pct, (float) $done->commission_amount, $done->commissionDue()], 'prestation réalisée après le changement : taux d’origine');
        $this->assertSame(['no_show', 10.0, 40.0], [$absent->status, (float) $absent->commission_pct, $absent->commissionDue()], 'client absent, frais appliqués : taux d’origine');
        $this->assertSame(['cancelled', 10.0, 0.0], [$cancelled->status, (float) $cancelled->commission_pct, $cancelled->commissionDue()], 'annulation sans frais : aucune commission');
        $this->assertEqualsCanonicalizing([40.0, 40.0], LedgerEntry::where('type', 'commission')->pluck('amount')->map(fn ($a) => (float) $a)->all(), 'écritures de commission au taux d’origine');

        $waived = $this->book('absent sans frais', '10:00', [['massage', 1]], true, 'confirmed', CarbonImmutable::parse($this->day)->addWeek()->format('Y-m-d'));
        $this->assertSame(30.0, (float) $waived->commission_pct, 'nouvelle réservation : taux du jour');
        $this->travelTo($waived->end_at->addHours(1)->toMutable());
        $this->bookings->noShow($waived, 'admin', false);
        $this->assertSame(0.0, $waived->fresh()->commissionDue(), 'absence sans frais : aucune commission');

        $before = Booking::orderBy('id')->get(['id', 'commission_pct', 'commission_amount', 'commissionable_amount'])->toArray();
        $ledger = LedgerEntry::orderBy('id')->get(['id', 'amount', 'type'])->toArray();
        $this->partner->commissionPeriods()->delete();
        Manager::applyLaunchOffer($this->partner, $today->subMonths(2)->format('Y-m-d'), 1, 6, 5);
        $this->partner->update(['commission_pct' => 0]);
        $this->assertSame($before, Booking::orderBy('id')->get(['id', 'commission_pct', 'commission_amount', 'commissionable_amount'])->toArray(), 'aucune modification de taux ne touche les réservations enregistrées');
        $this->assertSame($ledger, LedgerEntry::orderBy('id')->get(['id', 'amount', 'type'])->toArray(), 'ni les écritures de commission');
    }

    public function test_partner_sheet_shows_current_rate_next_rate_date_and_days_left(): void
    {
        $today = CarbonImmutable::now(config('hl.timezone'));
        $this->assertSame('15 % — '.__('admin.commission_standard'), PartnerResource::rateNow($this->partner->fresh()));
        $this->assertSame(__('admin.rate_next_none'), PartnerResource::rateNext($this->partner->fresh()));

        Manager::applyLaunchOffer($this->partner, $today->subDays(20)->format('Y-m-d'), 1, 6, 10);
        $p = $this->partner->fresh();
        $change = $today->subDays(20)->addMonthNoOverflow();
        $this->assertSame('0 % — '.__('admin.launch_free_label'), PartnerResource::rateNow($p));
        $this->assertSame(__('admin.rate_next_value', ['pct' => 10, 'date' => $change->format('d/m/Y'), 'days' => (int) $today->startOfDay()->diffInDays($change->startOfDay())]), PartnerResource::rateNext($p));
        $this->actingAs($this->admin)->get('/admin/partners/'.$p->id.'/edit')->assertOk()
            ->assertSee(__('admin.rate_now'))->assertSee(__('admin.rate_next'))->assertSee($change->format('d/m/Y'));

        // Gestion manuelle conservée : sans période, le taux saisi à la main s'applique seul.
        $p->commissionPeriods()->delete();
        $p->update(['commission_pct' => 12]);
        $this->assertSame('12 % — '.__('admin.commission_standard'), PartnerResource::rateNow($p->fresh()));
        $this->assertSame(__('admin.rate_next_none'), PartnerResource::rateNext($p->fresh()));
    }

    public function test_admin_screen_shows_periods_and_commercial_distinction(): void
    {
        Manager::applyLaunchOffer($this->partner, CarbonImmutable::now(config('hl.timezone'))->format('Y-m-d'), 1, 6, 10);
        $this->actingAs($this->admin)->get('/admin/partners')->assertOk()->assertSee(__('admin.commercial_partner'))->assertSee(__('admin.launch_free_label'));
        $this->actingAs($this->admin)->get('/admin/partners/'.$this->partner->id.'/edit')->assertOk();
        Livewire::actingAs($this->admin)->test(Manager::class, ['ownerRecord' => $this->partner, 'pageClass' => EditPartner::class])
            ->assertSuccessful()->assertSee(__('admin.launch_promo_label'))->assertSee(__('admin.period_current'))
            ->callTableAction('create', data: ['label' => 'Doublon', 'commission_pct' => 5, 'starts_on' => now()->format('Y-m-d'), 'ends_on' => null])
            ->assertHasTableActionErrors(['starts_on']);
        Livewire::actingAs($this->admin)->test(Manager::class, ['ownerRecord' => $this->partner, 'pageClass' => EditPartner::class])
            ->callTableAction('launchOffer', data: ['start' => now()->format('Y-m-d'), 'free_months' => 1, 'promo_months' => 0, 'promo_pct' => 10])
            ->assertHasTableActionErrors(['start']);
        $this->assertSame(2, $this->partner->commissionPeriods()->count());
        $this->actingAs($this->spa->partner->user)->get('/admin/partners')->assertForbidden();
    }

    public function test_sheet_summary_refreshes_as_soon_as_periods_change(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $page = Livewire::actingAs($this->admin)->test(EditPartner::class, ['record' => $this->partner->id])
            ->assertSee(__('admin.rate_next_none'));
        Livewire::actingAs($this->admin)->test(Manager::class, ['ownerRecord' => $this->partner, 'pageClass' => EditPartner::class])
            ->callTableAction('launchOffer', data: ['start' => now()->format('Y-m-d'), 'free_months' => 1, 'promo_months' => 6, 'promo_pct' => 10])
            ->assertDispatched('commission-periods-changed');
        $page->dispatch('commission-periods-changed')->assertDontSee(__('admin.rate_next_none'))->assertSee(__('admin.launch_free_label'));
    }

    public function test_activity_log_can_be_filtered_by_partner_with_readable_labels(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs($this->admin);
        $a = $this->partner;
        $u = User::factory()->create(['role' => 'partner']);
        $b = Partner::create(['user_id' => $u->id, 'company_name' => 'Beta SARL', 'status' => 'approved']);
        $la = ActivityLog::record('partner.commission_period_extended', $a, ['pct' => 0]);
        $lb = ActivityLog::record('partner.commission_period_created', $b, ['pct' => 10]);

        Livewire::test(ListActivityLogs::class)
            ->filterTable('partner', $a->id)
            ->assertCanSeeTableRecords([$la])
            ->assertCanNotSeeTableRecords([$lb])
            ->assertSee('Période commerciale prolongée')
            ->assertSee($a->company_name);
    }
}
