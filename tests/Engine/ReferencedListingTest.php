<?php

namespace Tests\Engine;

use App\Domain\Booking\BookingException;
use App\Domain\Booking\BookingService;
use App\Domain\Booking\CapacityEngine;
use App\Domain\Catalogue\PublicationChecklist;
use App\Filament\Shared\BookingActions;
use Carbon\CarbonImmutable;

/** Établissement référencé : demandes sans ressources modélisées, commission nulle, délai en heures de service, annulation « à confirmer ». */
class ReferencedListingTest extends BookingFlowTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->spa->update(['listing_type' => 'referenced', 'managed_by' => 'admin', 'cancellation_hours' => null, 'instant_booking' => true]);
        $this->spa->resources()->delete();
        $this->spa = $this->reload();
    }

    public function test_slots_and_booking_without_resources(): void
    {
        $this->assertTrue($this->spa->isReferenced());
        $this->assertFalse($this->spa->instantBookingActive(), 'Jamais de réservation instantanée pour une fiche référencée');
        $av = $this->availability(['treatment' => $this->hm->id, 'party' => 2]);
        $this->assertContains('10:00', $av['times'], 'Créneaux d’après les horaires d’ouverture, sans ressource : '.$av['reason']);
        $this->assertNotContains('23:00', $av['times'], 'Hors horaires : aucun créneau');

        $b = $this->book('référencé', '10:00', [[$this->hm->slug, 2]], true, 'waiting');
        $this->assertSame('waiting', $b->status);
        $this->assertSame([], $this->activeAllocations($b), 'Aucune ressource affectée');
        $this->assertSame(0.0, (float) $b->commission_pct, 'Commission nulle');
        $this->assertSame(0.0, (float) $b->commission_amount);
        $this->assertNull($b->cancellation_hours, 'Conditions d’annulation non confirmées → null');
        $this->assertTrue($b->cancellationToConfirm());
        $this->assertSame(0.0, $b->cancelFeeNow(), 'Aucun frais d’annulation sans conditions confirmées');

        $b2 = $this->book('référencé 2', '10:00', [[$this->hm->slug, 2]], true, 'waiting');
        $this->assertSame('waiting', $b2->status, 'Pas de blocage de capacité : une 2e demande au même créneau est acceptée (vérification avec l’établissement)');

        $svc = app(BookingService::class);
        try {
            $svc->accept($b->fresh(), 'admin');
            $this->fail('Fiche référencée : aucune confirmation sans attestation de vérification auprès de l’établissement');
        } catch (BookingException $e) {
            $this->assertSame(__('booking.spa_check_required'), $e->getMessage());
        }
        $this->assertSame('confirmed', $svc->accept($b->fresh(), 'admin', null, 'Appel 14 h 20, accueil : OK 2 pers.')->status, 'Acceptation Admin sans ressources modélisées');
        $this->assertSame(1, $b->fresh()->events()->where('type', 'spa:verified')->count(), 'Vérification journalisée');
        $this->assertSame([$b->reference], $svc->confirmedOverlaps($b2->fresh())->pluck('reference')->all(), 'La 2e demande voit la 1re déjà confirmée sur le créneau');
        $this->assertStringContainsString($b->reference, BookingActions::acceptHelp($b2->fresh()), 'Avertissement double confirmation dans l’Admin');
        $this->assertSame('declined', $svc->decline($b2->fresh(), 'admin', 'treatment_unavailable')->status, 'Refus Admin sans ressources modélisées');
    }

    public function test_checklist_ignores_resources_and_staff(): void
    {
        $checks = PublicationChecklist::checks($this->spa);
        $this->assertTrue($checks[__('admin.chk_resources')], 'Ressources non requises pour publier une fiche référencée');
        $this->assertTrue($checks[__('admin.chk_staff')]);
        $this->spa->update(['listing_type' => 'partner']);
        $checks = PublicationChecklist::checks($this->reload());
        $this->assertFalse($checks[__('admin.chk_resources')], 'Fiche partenaire : ressources exigées');
    }

    public function test_waiting_deadline_runs_in_service_hours(): void
    {
        config(['hl.admin_hours' => [9, 21]]);
        $svc = app(BookingService::class);
        $spa = $this->reload();
        $d = $svc->waitingDeadline(CarbonImmutable::parse('2026-06-01 14:00'), $spa);
        $this->assertSame('2026-06-01 16:00', $d->format('Y-m-d H:i'), 'En journée : 2 h pleines');
        $d = $svc->waitingDeadline(CarbonImmutable::parse('2026-06-01 20:30'), $spa);
        $this->assertSame('2026-06-02 10:30', $d->format('Y-m-d H:i'), 'Le soir : 30 min ce jour, 1 h 30 le lendemain dès 9 h');
        $d = $svc->waitingDeadline(CarbonImmutable::parse('2026-06-01 23:15'), $spa);
        $this->assertSame('2026-06-02 11:00', $d->format('Y-m-d H:i'), 'La nuit : délai à partir de 9 h le lendemain');
        $spa->update(['managed_by' => 'partner']);
        $d = $svc->waitingDeadline(CarbonImmutable::parse('2026-06-01 23:15'), $this->reload());
        $this->assertSame('2026-06-02 01:15', $d->format('Y-m-d H:i'), 'Fiche gérée par le partenaire : délai continu inchangé');
    }

    public function test_partner_spa_still_needs_resources(): void
    {
        $this->spa->update(['listing_type' => 'partner']);
        $av = app(CapacityEngine::class)->availability($this->reload(), $this->day, [['treatment' => $this->hm->id, 'party' => 2]]);
        $this->assertSame([], $av['times'], 'Sans ressources, une fiche partenaire n’a aucun créneau');
    }

    public function test_last_minute_requests_leave_time_for_verification(): void
    {
        config(['hl.admin_hours' => [9, 21], 'hl.referenced_lead_margin_minutes' => 30]);
        CarbonImmutable::setTestNow(CarbonImmutable::parse($this->day)->subDay()->setTime(23, 15));
        try {
            $spa = $this->reload();
            $this->assertSame(735, $spa->minLead(), 'Nuit : échéance 11 h le lendemain + 30 min de marge');
            $this->book('dernière minute', '10:00', [[$this->hm->slug, 1]], false);
            $b = $this->book('après vérification', '11:30', [[$this->hm->slug, 1]], true, 'waiting');
            $this->assertSame($this->day.' 11:00', $b->expires_at->format('Y-m-d H:i'), 'Expiration = échéance de réponse, toujours avant le rendez-vous');
            $this->assertTrue($b->expires_at->lt($b->start_at));
            $spa->update(['listing_type' => 'partner']);
            $this->assertLessThan(735, $this->reload()->minLead(), 'Fiche partenaire : délai minimal inchangé (pas de délai de vérification)');
        } finally {
            CarbonImmutable::setTestNow();
        }
    }
}
