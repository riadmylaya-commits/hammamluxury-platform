<?php

namespace Tests\Engine;

use App\Filament\Admin\Resources\PartnerResource\Pages\EditPartner;
use App\Filament\Admin\Resources\PartnerResource\RelationManagers\CommissionPeriodsRelationManager as Manager;
use App\Models\ActivityLog;
use App\Models\Partner;
use App\Models\User;
use Carbon\CarbonImmutable;
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

        $this->onDay('2026-12-20');
        $this->artisan('hl:commission-alerts')->assertSuccessful();
        $this->assertSame(0, ActivityLog::where('action', 'partner.commission_change_alerted')->count(), 'plus de 7 jours avant : rien');

        $this->onDay('2026-12-25');
        $this->artisan('hl:commission-alerts')->assertSuccessful();
        $this->artisan('hl:commission-alerts')->assertSuccessful();
        $logs = ActivityLog::where('action', 'partner.commission_change_alerted')->get();
        $this->assertCount(1, $logs, 'une seule alerte par changement');
        $this->assertSame(['on' => '2027-01-01', 'from' => 0, 'to' => 10], array_map(fn ($v) => is_float($v) ? (int) $v : $v, $logs[0]->properties));

        $this->onDay('2027-06-28');
        $this->artisan('hl:commission-alerts')->assertSuccessful();
        $last = ActivityLog::where('action', 'partner.commission_change_alerted')->latest('id')->first();
        $this->assertSame('2027-07-01', $last->properties['on']);
        $this->assertEquals([10, 15], [$last->properties['from'], $last->properties['to']]);
        $this->assertSame(2, ActivityLog::where('action', 'partner.commission_change_alerted')->count());
    }

    public function test_admin_screen_shows_periods_and_commercial_distinction(): void
    {
        Manager::applyLaunchOffer($this->partner, CarbonImmutable::now(config('hl.timezone'))->format('Y-m-d'), 1, 6, 10);
        $this->actingAs($this->admin)->get('/admin/partners')->assertOk()->assertSee(__('admin.commercial_partner'))->assertSee(__('admin.launch_free_label'));
        $this->actingAs($this->admin)->get('/admin/partners/'.$this->partner->id.'/edit')->assertOk();
        Livewire::actingAs($this->admin)->test(Manager::class, ['ownerRecord' => $this->partner, 'pageClass' => EditPartner::class])
            ->assertSuccessful()->assertSee(__('admin.launch_promo_label'))->assertSee(__('admin.period_current'))
            ->callTableAction('create', data: ['label' => 'Doublon', 'commission_pct' => 5, 'starts_on' => now()->format('Y-m-d'), 'ends_on' => null])
            ->assertHasTableActionErrors();
        $this->assertSame(2, $this->partner->commissionPeriods()->count());
        $this->actingAs($this->spa->partner->user)->get('/admin/partners')->assertForbidden();
    }
}
