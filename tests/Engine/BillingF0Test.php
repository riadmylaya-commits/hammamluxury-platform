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

    protected function tearDown(): void
    {
        $this->travelBack();
        parent::tearDown();
    }

    /** Simule la livraison future des lots d'émission (constante de code), sans toucher au code de production. */
    private function bindDeliveredLotsGate(): void
    {
        $this->app->bind(BillingGate::class, fn ($app, array $params) => new class($params['settings'] ?? $app->make(BillingSettings::class)) extends BillingGate
        {
            protected function issuanceLotsDelivered(): bool
            {
                return true;
            }
        });
    }

    private function fiscalComplete(BillingSettings $s): void
    {
        $s->set('start_period', '2027-01');
        $s->set('vat_mode', 'ht_plus_vat');
        $s->set('vat_rate', 20);
        $s->set('accountant_validated_at', '2026-12-15');
        $this->issuerComplete($s);
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

        $this->fiscalComplete($s);
        $release = ['lots_not_delivered', 'acceptance_not_recorded', 'owner_authorization_missing'];
        $this->assertSame($release, array_values(array_diff(app(BillingGate::class)->globalBlockers(), ['billing_disabled'])));

        // Informations fiscales et date de démarrage complètes : l'activation reste refusée côté serveur.
        try {
            $s->set('enabled', true);
            $this->fail('enabled must be refused while release locks remain');
        } catch (BillingLockedException $e) {
            $this->assertSame($release, $e->blockers);
        }
        $this->assertDatabaseMissing('billing_settings', ['key' => 'enabled']);

        // Recette et autorisation enregistrées sur le serveur : le verrou de code des lots reste actif.
        config(['hl.billing.acceptance_ref' => 'Recette staging 2027-01-05', 'hl.billing.owner_authorization_ref' => 'Accord écrit 2027-01-06']);
        try {
            $s->set('enabled', true);
            $this->fail('enabled must be refused while issuance lots are not delivered');
        } catch (BillingLockedException $e) {
            $this->assertSame(['lots_not_delivered'], $e->blockers);
        }
        $this->assertFalse($s->enabled());

        $this->bindDeliveredLotsGate();
        $s->set('enabled', true);
        $this->assertTrue($s->enabled());
        $this->assertTrue(app(BillingGate::class)->canIssue());
        $log = ActivityLog::where('action', 'billing.setting_changed')->whereJsonContains('properties->key', 'enabled')->first();
        $this->assertNotNull($log);
        $this->assertSame($this->admin->id, $log->user_id);
        $this->assertFalse($log->properties['old']);
        $this->assertTrue($log->properties['new']);
    }

    public function test_release_locks_cannot_be_lifted_by_settings_table_or_admin_form(): void
    {
        $this->actingAs($this->admin);
        $s = app(BillingSettings::class);
        $this->fiscalComplete($s);
        // Écriture directe en base (contournement de l'interface) : l'émission reste refusée.
        DB::table('billing_settings')->insert(['key' => 'enabled', 'value' => '1', 'created_at' => now(), 'updated_at' => now()]);
        $gate = app(BillingGate::class);
        $this->assertTrue(app(BillingSettings::class)->enabled());
        $this->assertFalse($gate->canIssue());
        try {
            $gate->assertCanIssue();
            $this->fail('issuance must stay locked');
        } catch (BillingLockedException $e) {
            $this->assertSame(['lots_not_delivered', 'acceptance_not_recorded', 'owner_authorization_missing'], $e->blockers);
        }
        // Les références de recette et d'autorisation ne sont pas des réglages : la table et le formulaire les ignorent.
        $this->assertNotContains('acceptance_ref', BillingSettings::KEYS);
        $this->assertNotContains('owner_authorization_ref', BillingSettings::KEYS);
        $this->expectException(\InvalidArgumentException::class);
        $s->set('owner_authorization_ref', 'contournement');
    }

    public function test_late_corrections_follow_their_original_commission_and_late_commissions_stay_billable(): void
    {
        $gate = fn () => app(BillingGate::class);
        $old = $this->book('massage', '10:00', [['massage', 1]], true);
        $late = $this->book('massage', '12:00', [['massage', 1]], true);

        // Prestation réalisée et commission acquise avant le démarrage : legacy.
        $this->travelTo($old->end_at->addHours(5)->toMutable());
        $this->bookings->complete($old, 'admin');
        $start = now()->addMonthNoOverflow()->format('Y-m');
        app(BillingSettings::class)->set('start_period', $start);

        // Après le démarrage : remboursement de l'ancienne prestation, et prestation ancienne clôturée tardivement.
        $this->travelTo(app(BillingSettings::class)->startPeriod()->addDays(2)->setTime(10, 0)->toMutable());
        $this->bookings->setPaymentStatus($old->fresh(), 'admin', 'refunded');
        $this->bookings->complete($late->fresh(), 'admin');

        $this->artisan('hl:billing-mark-legacy')->expectsOutputToContain('Corrections postérieures au démarrage rattachées à une commission legacy : 1')->assertSuccessful();
        $this->artisan('hl:billing-mark-legacy', ['--apply' => true])->assertSuccessful();
        $oldEntries = LedgerEntry::where('booking_id', $old->id)->orderBy('id')->get();
        $this->assertSame(['commission', 'commission_reversal'], $oldEntries->pluck('type')->all());
        $this->assertSame(['legacy', 'legacy'], $oldEntries->pluck('billing_scope')->all(), 'La contre-écriture tardive ne doit pas réduire une facture.');
        $lateEntry = LedgerEntry::where('booking_id', $late->id)->sole();
        $this->assertSame('billable', $lateEntry->billing_scope, 'Commission acquise après le démarrage pour une prestation ancienne : facturable.');
        $this->assertSame(60.0, $lateEntry->amount);

        // Nouvelles écritures après qualification : l'émission est bloquée tant qu'elles ne sont pas qualifiées.
        $this->bookings->setPaymentStatus($old->fresh(), 'admin', 'paid');
        $this->bookings->setPaymentStatus($late->fresh(), 'admin', 'refunded');
        $this->assertContains('ledger_unqualified_entries', $gate()->globalBlockers());
        $this->artisan('hl:billing-mark-legacy', ['--apply' => true])->assertSuccessful();
        $this->assertNotContains('ledger_unqualified_entries', $gate()->globalBlockers());
        $this->assertSame(0, LedgerEntry::whereNull('billing_scope')->count());
        $this->assertSame('legacy', LedgerEntry::where('booking_id', $old->id)->orderByDesc('id')->first()->billing_scope);
        $this->assertSame('billable', LedgerEntry::where('booking_id', $late->id)->orderByDesc('id')->first()->billing_scope);

        // Rien n'est perdu ni compté deux fois : chaque chaîne garde son solde dans un seul périmètre.
        $this->assertSame(60.0, (float) LedgerEntry::where('booking_id', $old->id)->where('billing_scope', 'legacy')->sum('amount'));
        $this->assertSame(0.0, (float) LedgerEntry::where('booking_id', $late->id)->where('billing_scope', 'billable')->sum('amount'));
        $this->assertSame(0, LedgerEntry::where('booking_id', $old->id)->where('billing_scope', 'billable')->count());
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

        // Une fois des écritures qualifiées, la date de démarrage est figée : aucune écriture ne change de périmètre.
        try {
            app(BillingSettings::class)->set('start_period', '2028-01');
            $this->fail('start_period must be frozen once entries are qualified');
        } catch (BillingLockedException $e) {
            $this->assertSame(['start_period_frozen'], $e->blockers);
        }
        $this->assertSame('2027-01', app(BillingSettings::class)->get('start_period'));
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
