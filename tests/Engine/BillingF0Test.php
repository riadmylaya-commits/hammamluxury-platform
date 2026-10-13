<?php

namespace Tests\Engine;

use App\Domain\Billing\BillingGate;
use App\Domain\Billing\BillingLockedException;
use App\Domain\Billing\BillingSettings;
use App\Filament\Admin\Pages\BillingSettingsPage;
use App\Filament\Partner\Pages\BillingProfile;
use App\Models\ActivityLog;
use App\Models\LedgerEntry;
use App\Models\Partner;
use App\Models\Spa;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;

/** Lot F0 facturation : référentiel légal, paramètres verrouillés, verrous serveur, qualification legacy, écrans et isolation. */
class BillingF0Test extends BookingFlowTestCase
{
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::create(['name' => 'Admin', 'email' => 'admin@example.test', 'password' => 'secret-test', 'role' => 'admin', 'email_verified_at' => now()]);
    }

    private function issuerComplete(BillingSettings $s): void
    {
        foreach (BillingSettings::ISSUER_REQUIRED as $f) {
            $s->set('issuer.'.$f, $f === 'ice' ? '001234567000089' : 'HL '.$f);
        }
    }

    private function completePartner(Partner $p): void
    {
        $p->update(['legal_form' => 'SARL', 'tax_id' => 'IF123', 'registry_number' => 'RC456', 'ice' => '001234567000089',
            'billing_address' => '1 rue Test', 'billing_city' => 'Marrakech', 'billing_email' => 'compta@example.test']);
    }

    public function test_migrations_add_additive_columns_only(): void
    {
        foreach (['ice', 'ice_not_applicable', 'ice_not_applicable_reason', 'billing_address', 'billing_city', 'billing_email'] as $c) {
            $this->assertTrue(Schema::hasColumn('partners', $c), $c);
        }
        $this->assertTrue(Schema::hasColumn('ledger_entries', 'billing_scope'));
        $this->assertTrue(Schema::hasTable('billing_settings'));
    }

    public function test_everything_is_locked_by_default(): void
    {
        $gate = app(BillingGate::class);
        $blockers = $gate->globalBlockers();
        foreach (['billing_disabled', 'start_period_missing', 'vat_mode_pending', 'accountant_not_validated', 'issuer_company_name_missing', 'issuer_bank_account_missing'] as $b) {
            $this->assertContains($b, $blockers);
        }
        $this->assertFalse($gate->canIssue());
        $this->expectException(BillingLockedException::class);
        $gate->assertCanIssue();
    }

    public function test_enabling_is_refused_while_other_locks_remain_and_is_logged_once_cleared(): void
    {
        $this->actingAs($this->admin);
        $s = app(BillingSettings::class);
        try {
            $s->set('enabled', true);
            $this->fail('enabled must be refused');
        } catch (BillingLockedException $e) {
            $this->assertContains('start_period_missing', $e->blockers);
            $this->assertNotContains('billing_disabled', $e->blockers);
        }
        $this->assertFalse($s->enabled());
        $this->assertDatabaseMissing('billing_settings', ['key' => 'enabled']);

        $s->set('start_period', '2027-01');
        $s->set('vat_mode', 'ht_plus_vat');
        $s->set('vat_rate', 20);
        $s->set('accountant_validated_at', '2026-12-15');
        $this->issuerComplete($s);
        $this->assertSame([], array_diff(app(BillingGate::class)->globalBlockers(), ['billing_disabled']));
        $s->set('enabled', true);
        $this->assertTrue($s->enabled());
        $this->assertTrue(app(BillingGate::class)->canIssue());
        $log = ActivityLog::where('action', 'billing.setting_changed')->whereJsonContains('properties->key', 'enabled')->first();
        $this->assertNotNull($log);
        $this->assertSame($this->admin->id, $log->user_id);
        $this->assertFalse($log->properties['old']);
        $this->assertTrue($log->properties['new']);
    }

    public function test_invalid_values_are_rejected(): void
    {
        $s = app(BillingSettings::class);
        foreach ([['start_period', '2027-13'], ['vat_mode', 'magic'], ['vat_rate', 150]] as [$k, $v]) {
            try {
                $s->set($k, $v);
                $this->fail("$k accepted $v");
            } catch (\InvalidArgumentException) {
                $this->assertDatabaseMissing('billing_settings', ['key' => $k]);
            }
        }
    }

    public function test_partner_blockers_require_legal_identity_with_ice_or_explicit_reason(): void
    {
        $gate = app(BillingGate::class);
        $p = $this->spa->partner;
        $this->assertContains('partner_ice_missing', $gate->partnerBlockers($p));
        $this->assertContains('partner_billing_address_missing', $gate->partnerBlockers($p));
        $this->completePartner($p);
        $this->assertSame([], $gate->partnerBlockers($p->fresh()));
        $this->assertTrue($gate->partnerReady($p->fresh()));

        $p->update(['ice' => null, 'ice_not_applicable' => true, 'ice_not_applicable_reason' => null]);
        $this->assertContains('partner_ice_reason_missing', $gate->partnerBlockers($p->fresh()));
        $p->update(['ice_not_applicable_reason' => 'Personne physique']);
        $this->assertTrue($gate->partnerReady($p->fresh()));
        $p->update(['ice_not_applicable' => false, 'ice' => '12345']);
        $this->assertContains('partner_ice_missing', $gate->partnerBlockers($p->fresh()));
    }

    public function test_commission_rules_are_untouched_by_billing_settings(): void
    {
        app(BillingSettings::class)->set('vat_mode', 'ht_plus_vat');
        $b = $this->book('massage', '10:00', [['massage', 1]], true);
        $this->assertSame(15.0, (float) $b->commission_pct);
        $this->assertSame(60.0, (float) $b->commission_amount);
    }

    public function test_mark_legacy_is_report_only_without_apply_and_never_requalifies(): void
    {
        $p = $this->spa->partner;
        $old = LedgerEntry::create(['partner_id' => $p->id, 'type' => 'commission', 'amount' => 60, 'currency' => 'MAD', 'idempotency_key' => 'k1']);
        DB::table('ledger_entries')->where('id', $old->id)->update(['created_at' => '2026-06-15 10:00:00']);
        $new = LedgerEntry::create(['partner_id' => $p->id, 'type' => 'commission', 'amount' => 75, 'currency' => 'MAD', 'idempotency_key' => 'k2']);
        DB::table('ledger_entries')->where('id', $new->id)->update(['created_at' => '2027-01-03 10:00:00']);

        $this->artisan('hl:billing-mark-legacy')->assertFailed();
        app(BillingSettings::class)->set('start_period', '2027-01');
        $this->artisan('hl:billing-mark-legacy')->expectsOutputToContain('legacy=1')->assertSuccessful();
        $this->assertNull($old->fresh()->billing_scope);

        $this->artisan('hl:billing-mark-legacy', ['--apply' => true])->assertSuccessful();
        $this->assertSame('legacy', $old->fresh()->billing_scope);
        $this->assertSame('billable', $new->fresh()->billing_scope);
        $this->assertSame(60.0, $old->fresh()->amount);
        $this->assertSame('2026-06-15 10:00:00', $old->fresh()->created_at->format('Y-m-d H:i:s'));
        $this->assertFalse(app(BillingGate::class)->isBillable($old->fresh()));
        $this->assertTrue(app(BillingGate::class)->isBillable($new->fresh()));
        $this->assertDatabaseHas('activity_logs', ['action' => 'billing.scope_marked']);

        // Reculer la date de démarrage ne requalifie jamais une écriture déjà qualifiée.
        app(BillingSettings::class)->set('start_period', '2028-01');
        $this->artisan('hl:billing-mark-legacy', ['--apply' => true])->assertSuccessful();
        $this->assertSame('billable', $new->fresh()->billing_scope);
    }

    public function test_ledger_entries_remain_append_only_through_eloquent(): void
    {
        $e = LedgerEntry::create(['partner_id' => $this->spa->partner_id, 'type' => 'commission', 'amount' => 1, 'currency' => 'MAD', 'idempotency_key' => 'k3']);
        $this->expectException(\LogicException::class);
        $e->update(['billing_scope' => 'legacy']);
    }

    public function test_admin_settings_page_shows_locks_and_saves_without_enabling(): void
    {
        $this->actingAs($this->admin)->get('/admin/billing-settings-page')->assertOk()->assertSee(__('billing.blocker.vat_mode_pending'));
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Livewire::actingAs($this->admin)->test(BillingSettingsPage::class)
            ->fillForm(['enabled' => true, 'start_period' => '2027-01', 'vat_mode' => 'pending_validation', 'issuer__company_name' => 'HammamLuxury SARL'])
            ->call('save')
            ->assertNotified(__('billing.locked'));
        $s = app(BillingSettings::class);
        $this->assertFalse($s->enabled());
        $this->assertSame('2027-01', $s->get('start_period'));
        $this->assertSame('HammamLuxury SARL', $s->get('issuer.company_name'));
        $this->actingAs($this->spa->partner->user)->get('/admin/billing-settings-page')->assertForbidden();
    }

    public function test_partner_billing_profile_is_tenant_bound_and_isolated(): void
    {
        $owner = $this->spa->partner->user;
        $other = User::create(['name' => 'Autre', 'email' => 'autre@example.test', 'password' => 'secret-test', 'role' => 'partner', 'email_verified_at' => now()]);
        $op = Partner::create(['user_id' => $other->id, 'company_name' => 'Autre SARL', 'status' => 'approved']);
        $otherSpa = Spa::create(['partner_id' => $op->id, 'slug' => 'autre-spa', 'name' => 'Autre Spa', 'city' => 'Fès', 'status' => 'draft']);

        $this->actingAs($owner)->get('/partenaire/'.$this->spa->slug.'/billing-profile')->assertOk()->assertSee(__('billing.profile_title'));
        $this->actingAs($owner)->get('/partenaire/'.$otherSpa->slug.'/billing-profile')->assertNotFound();

        Filament::setCurrentPanel(Filament::getPanel('partner'));
        Filament::setTenant($this->spa, true);
        Livewire::actingAs($owner)->test(BillingProfile::class)
            ->fillForm(['legal_form' => 'SARL', 'tax_id' => 'IF1', 'registry_number' => 'RC1', 'ice' => '001234567000089', 'billing_address' => '1 rue Test', 'billing_city' => 'Marrakech', 'billing_email' => 'compta@example.test'])
            ->call('save')->assertHasNoFormErrors()->assertNotified(__('billing.profile_saved'));
        $this->assertSame('001234567000089', $this->spa->partner->fresh()->ice);
        $this->assertNull($op->fresh()->ice);
        $this->assertDatabaseHas('activity_logs', ['action' => 'partner.billing_profile_updated', 'subject_id' => $this->spa->partner_id]);

        Livewire::actingAs($owner)->test(BillingProfile::class)
            ->fillForm(['ice' => '123'])->call('save')->assertHasFormErrors(['ice']);
        $this->assertSame('001234567000089', $this->spa->partner->fresh()->ice);
        // Un ICE invalide laissé dans le champ ne bloque plus dès que « Pas d'ICE » est coché avec un motif.
        Livewire::actingAs($owner)->test(BillingProfile::class)
            ->fillForm(['ice' => '123', 'ice_not_applicable' => true, 'ice_not_applicable_reason' => 'Personne physique'])
            ->call('save')->assertHasNoFormErrors();
        $this->assertNull($this->spa->partner->fresh()->ice);
        $this->assertTrue($this->spa->partner->fresh()->ice_not_applicable);
    }
}
