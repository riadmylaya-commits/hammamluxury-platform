<?php

namespace Tests\Engine;

use App\Domain\Booking\BookingException;
use App\Domain\Booking\CancellationService;
use App\Livewire\Site\BookingShow;
use App\Models\Booking;
use App\Models\LedgerEntry;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use LogicException;
use RuntimeException;

/** Ces tests écrivent uniquement dans la base de test ; ne jamais les lancer sur staging/production. */
class FinanceIntegrityTest extends BookingFlowTestCase
{
    protected function tearDown(): void
    {
        $this->travelBack();
        parent::tearDown();
    }

    private function confirmed(): Booking
    {
        $result = $this->submit($this->intent('10:00', ['treatment' => $this->m->id, 'party' => 1]));
        $this->assertTrue($result['ok'], $result['error']);
        $booking = $result['booking'];
        $this->bookings->accept($booking, 'partner');

        return $booking->fresh();
    }

    private function completed(): Booking
    {
        $booking = $this->confirmed();
        $this->travelTo($booking->end_at->addHours(5)->toMutable());
        $this->bookings->complete($booking, 'admin');

        return $booking->fresh();
    }

    private function balance(Booking $booking): float
    {
        return (float) LedgerEntry::where('booking_id', $booking->id)
            ->whereIn('type', LedgerEntry::COMMISSION_TYPES)->sum('amount');
    }

    public function test_confirmed_and_declared_paid_are_forecasts_until_completion(): void
    {
        $booking = $this->confirmed();
        $this->assertSame(60.0, (float) $booking->commission_amount);
        $this->assertSame(340.0, $booking->netForPartner());
        $this->assertSame(0.0, $booking->commissionDue());
        $this->assertSame(0.0, $booking->netDue());
        $this->assertFalse($booking->hasAccruedAmount());

        $this->bookings->setPaymentStatus($booking, 'partner', 'paid');
        $booking->refresh();
        $this->assertSame('confirmed', $booking->status);
        $this->assertSame('paid', $booking->payment_status);
        $this->assertSame(0.0, $this->balance($booking));
        $this->assertSame(0, LedgerEntry::where('booking_id', $booking->id)->count());
    }

    public function test_completed_booking_cannot_be_cancelled_by_client_api_or_livewire(): void
    {
        $booking = $this->completed();
        $this->assertFalse($booking->canCancel('client'));
        $events = $booking->events()->count();
        $this->postJson('/api/v1/bookings/'.$booking->manage_token.'/cancel')->assertStatus(409);
        Livewire::test(BookingShow::class, ['token' => $booking->manage_token])
            ->call('cancel')->assertSet('error', fn (string $message) => $message !== '');

        $this->assertSame('completed', $booking->fresh()->status);
        $this->assertSame(60.0, $this->balance($booking));
        $this->assertSame($events, $booking->events()->count());
    }

    public function test_client_cancellation_deadline_is_shared_by_api_and_livewire(): void
    {
        $booking = $this->confirmed();
        $this->travelTo($booking->start_at->toMutable());
        $this->assertFalse($booking->fresh()->canCancel('client'));
        $this->postJson('/api/v1/bookings/'.$booking->manage_token.'/cancel')->assertStatus(409);
        Livewire::test(BookingShow::class, ['token' => $booking->manage_token])->call('cancel')
            ->assertSet('error', fn (string $message) => $message !== '');
        $this->assertSame('confirmed', $booking->fresh()->status);
        $this->assertGreaterThan(0, $booking->allocations()->where('status', 'active')->count());
    }

    public function test_admin_no_show_correction_keeps_original_commission_and_adds_reversal(): void
    {
        $booking = $this->completed();
        $initial = LedgerEntry::where('booking_id', $booking->id)->firstOrFail();
        $this->bookings->noShow($booking, 'admin', false, 'Correction après vérification du justificatif.');
        $entries = LedgerEntry::where('booking_id', $booking->id)->orderBy('id')->get();

        $this->assertCount(2, $entries);
        $this->assertSame($initial->id, $entries[0]->id);
        $this->assertSame(60.0, $entries[0]->amount);
        $this->assertSame(-60.0, $entries[1]->amount);
        $this->assertSame('commission_reversal', $entries[1]->type);
        $this->assertSame($initial->id, $entries[1]->corrects_entry_id);
        $this->assertSame('admin', $entries[1]->actor);
        $this->assertSame($booking->reference, $entries[1]->booking_reference);
        $this->assertSame(0.0, $this->balance($booking));
        $this->assertSame(0.0, $booking->fresh()->netDue());
    }

    public function test_partner_absence_reverses_commission_without_deleting_history(): void
    {
        $booking = $this->completed();
        $this->bookings->partnerNoShow($booking, 'admin', 'Établissement fermé à l’arrivée du client.');
        $this->assertSame(2, LedgerEntry::where('booking_id', $booking->id)->count());
        $this->assertSame(0.0, $this->balance($booking));
        $this->assertSame(0.0, $booking->fresh()->amountDue());
    }

    public function test_full_refund_and_correction_are_linked_and_retries_are_idempotent(): void
    {
        $booking = $this->completed();
        $this->bookings->setPaymentStatus($booking, 'admin', 'refunded');
        $this->bookings->setPaymentStatus($booking, 'admin', 'refunded');
        $this->assertSame(2, LedgerEntry::where('booking_id', $booking->id)->count());
        $this->assertSame(0.0, $this->balance($booking));
        $this->assertSame(0.0, $booking->fresh()->commissionDue());
        $this->assertSame('completed', $booking->fresh()->status, 'Le paiement ne réécrit pas la prestation réalisée.');

        $this->bookings->setPaymentStatus($booking, 'admin', 'paid');
        $this->bookings->setPaymentStatus($booking, 'admin', 'paid');
        $entries = LedgerEntry::where('booking_id', $booking->id)->orderBy('id')->get();
        $this->assertCount(3, $entries);
        $this->assertSame([60.0, -60.0, 60.0], $entries->pluck('amount')->all());
        $this->assertSame($entries[1]->id, $entries[2]->corrects_entry_id);
        $this->assertSame('commission_adjust', $entries[2]->type);
        $this->assertSame(60.0, $this->balance($booking));
        $this->assertSame(340.0, $booking->fresh()->netDue());
    }

    public function test_partial_payment_declaration_does_not_invent_a_partial_refund_amount(): void
    {
        $booking = $this->completed();
        $this->bookings->setPaymentStatus($booking, 'partner', 'partial');
        $this->assertSame(60.0, $this->balance($booking));
        $this->assertSame(400.0, $booking->fresh()->amountDue());
        $this->assertSame(1, LedgerEntry::where('booking_id', $booking->id)->count());
    }

    public function test_no_show_fee_due_and_waived_fee_have_distinct_financial_outcomes(): void
    {
        $charged = $this->confirmed();
        $free = $this->confirmed();
        $this->travelTo($charged->start_at->addMinute()->toMutable());
        $this->bookings->noShow($charged, 'partner', true);
        $this->bookings->noShow($free, 'partner', false);
        $this->assertSame(400.0, $charged->fresh()->amountDue());
        $this->assertSame(60.0, $this->balance($charged));
        $this->assertSame(340.0, $charged->fresh()->netDue());
        $this->assertSame(0.0, $free->fresh()->amountDue());
        $this->assertSame(0.0, $this->balance($free));
        $this->assertSame(0, LedgerEntry::where('booking_id', $free->id)->count());

        $this->bookings->setPaymentStatus($charged, 'admin', 'refunded');
        $this->assertSame(0.0, $this->balance($charged));
        $this->assertSame(2, LedgerEntry::where('booking_id', $charged->id)->count());
    }

    public function test_original_ledger_entries_cannot_be_edited_or_deleted_through_model(): void
    {
        $entry = LedgerEntry::where('booking_id', $this->completed()->id)->firstOrFail();
        foreach (['save', 'delete'] as $operation) {
            try {
                $entry->amount = 0;
                $entry->{$operation}();
                $this->fail('Historique financier modifié.');
            } catch (LogicException $e) {
                $this->assertStringContainsString('append-only', $e->getMessage());
            }
        }
        $this->assertSame(60.0, $entry->fresh()->amount);
    }

    public function test_database_unique_key_rejects_a_duplicate_commission_operation(): void
    {
        $entry = LedgerEntry::where('booking_id', $this->completed()->id)->firstOrFail();
        $this->expectException(QueryException::class);
        $entry->replicate()->save();
    }

    public function test_migration_stops_before_schema_changes_when_legacy_commissions_are_duplicated(): void
    {
        $booking = $this->confirmed();
        for ($i = 0; $i < 2; $i++) {
            DB::table('ledger_entries')->insert([
                'booking_id' => $booking->id, 'partner_id' => $this->spa->partner_id,
                'type' => 'commission', 'amount' => 60, 'currency' => 'MAD',
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        $migration = require database_path('migrations/2026_10_12_100001_commission_ledger_integrity.php');
        try {
            $migration->up();
            $this->fail('Le doublon historique exige un rapprochement manuel.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Duplicate legacy commission', $e->getMessage());
            $this->assertStringContainsString((string) $booking->id, $e->getMessage());
        }
        $this->assertSame(2, LedgerEntry::where('booking_id', $booking->id)->count());
        $this->assertSame(120.0, $this->balance($booking));
    }

    public function test_partner_request_client_response_and_admin_decision_exact_replays_do_not_duplicate(): void
    {
        $booking = $this->confirmed();
        $admin = User::create(['name' => 'Admin', 'email' => 'finance-admin@example.test', 'password' => 'test-secret', 'role' => 'admin']);
        $service = app(CancellationService::class);
        $reason = 'Thérapeute hospitalisée, aucun remplaçant disponible.';
        $request = $service->request($booking, $this->spa->partner->user, $reason, 'staff_unavailable');
        Mail::fake();
        $retry = $service->request($booking, $this->spa->partner->user, $reason, 'staff_unavailable');
        $this->assertSame($request->id, $retry->id);
        $this->assertSame(1, $booking->cancellationRequests()->count());
        Mail::assertNothingQueued();

        $service->clientRespond($request, 'accepted');
        $service->clientRespond($request, 'accepted');
        $this->assertSame(1, $booking->events()->where('type', 'cancellation:client_accepted')->count());
        $service->decide($request, $admin, 'accepted');
        $service->decide($request, $admin, 'accepted');
        $this->assertSame('cancelled', $booking->fresh()->status);
        $this->assertSame(1, $booking->events()->where('type', 'cancelled')->count());
        $this->assertSame(0.0, $this->balance($booking));
    }

    public function test_pending_request_cannot_cancel_a_booking_completed_in_the_meantime(): void
    {
        $booking = $this->confirmed();
        $admin = User::create(['name' => 'Admin', 'email' => 'late-admin@example.test', 'password' => 'test-secret', 'role' => 'admin']);
        $service = app(CancellationService::class);
        $request = $service->request($booking, $this->spa->partner->user, 'Fermeture pour travaux urgents, proposition en attente.');
        $this->travelTo($booking->end_at->addHours(5)->toMutable());
        $this->bookings->complete($booking, 'admin');
        try {
            $service->decide($request, $admin, 'accepted');
            $this->fail('Une ancienne demande ne doit pas annuler une prestation clôturée.');
        } catch (BookingException $e) {
            $this->assertSame(409, $e->status);
        }
        $this->assertSame('completed', $booking->fresh()->status);
        $this->assertSame(60.0, $this->balance($booking));
    }

    public function test_historical_reconciliation_is_read_only_by_default_and_apply_is_idempotent(): void
    {
        $booking = $this->completed();
        // État laissé par l'ancien code : remboursement déclaré, commission jamais corrigée.
        DB::table('bookings')->where('id', $booking->id)->update(['payment_status' => 'refunded']);
        $this->artisan('hl:reconcile-commissions', ['--booking' => [$booking->id]])->assertExitCode(1);
        $this->assertSame(1, LedgerEntry::where('booking_id', $booking->id)->count());
        $this->assertSame(60.0, $this->balance($booking));

        $this->artisan('hl:reconcile-commissions', ['--booking' => [$booking->id], '--apply' => true])->assertExitCode(0);
        $this->assertSame(2, LedgerEntry::where('booking_id', $booking->id)->count());
        $this->assertSame(0.0, $this->balance($booking));
        $this->artisan('hl:reconcile-commissions', ['--booking' => [$booking->id], '--apply' => true])->assertExitCode(0);
        $this->assertSame(2, LedgerEntry::where('booking_id', $booking->id)->count());
    }
}
