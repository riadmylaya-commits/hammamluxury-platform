<?php

namespace Tests\Engine;

use App\Domain\Booking\BookingException;
use App\Models\Allocation;
use App\Models\Booking;
use App\Models\BookingEvent;
use App\Models\LedgerEntry;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use RuntimeException;

/** Régressions des transitions : les instances déjà chargées ne font jamais autorité. */
class BookingStateIntegrityTest extends BookingFlowTestCase
{
    private function waiting(string $time = '10:00'): Booking
    {
        return $this->bookings->book($this->spa, $this->at($time), [
            ['treatment' => $this->m->id, 'party' => 1],
        ], $this->customer);
    }

    private function assertConflict(callable $operation): BookingException
    {
        try {
            $operation();
            $this->fail('La transition devenue impossible doit être refusée.');
        } catch (BookingException $exception) {
            $this->assertSame(409, $exception->status);
            $this->assertNotSame('', $exception->reason);
            $this->assertNotSame('', $exception->getMessage());

            return $exception;
        }
    }

    public function test_stale_accept_cannot_resurrect_an_expired_booking(): void
    {
        $booking = $this->waiting();
        $stale = $booking->fresh();
        Booking::whereKey($booking->id)->update(['expires_at' => now()->subMinute()]);
        $this->assertSame([$booking->id], $this->bookings->expireWaiting());
        Mail::fake();

        $this->assertConflict(fn () => $this->bookings->accept($stale));

        $this->assertSame('expired', $booking->fresh()->status);
        $this->assertSame([], $this->activeAllocations($booking));
        $this->assertSame(0, $booking->events()->where('type', 'confirmed')->count());
        Mail::assertNothingQueued();
    }

    public function test_stale_accept_cannot_resurrect_a_declined_booking(): void
    {
        $booking = $this->waiting();
        $stale = $booking->fresh();
        $this->bookings->decline($booking, 'partner', 'closed');

        $this->assertConflict(fn () => $this->bookings->accept($stale));

        $this->assertSame('declined', $booking->fresh()->status);
        $this->assertSame([], $this->activeAllocations($booking));
        $this->assertSame(1, $booking->declines()->count());
        $this->assertSame(0, $booking->events()->where('type', 'confirmed')->count());
    }

    public function test_stale_accept_cannot_reclaim_a_cancelled_slot_already_sold_again(): void
    {
        $booking = $this->bookings->book($this->spa, $this->at('16:00'), [
            ['treatment' => $this->hs->id, 'party' => 1],
        ], $this->customer);
        $stale = $booking->fresh();
        $this->bookings->cancel($booking, 'client');
        $replacement = $this->bookings->book($this->spa, $this->at('16:00'), [
            ['treatment' => $this->hs->id, 'party' => 1],
        ], $this->customer, 'confirmed');

        $this->assertConflict(fn () => $this->bookings->accept($stale));

        $this->assertSame('cancelled', $booking->fresh()->status);
        $this->assertSame([], $this->activeAllocations($booking));
        $this->assertSame('confirmed', $replacement->fresh()->status);
        $this->assertCount(2, $this->activeAllocations($replacement));
    }

    public function test_accept_checks_current_expiry_even_without_the_expiration_worker(): void
    {
        $booking = $this->waiting();
        Booking::whereKey($booking->id)->update(['expires_at' => now()->subSecond()]);

        $this->assertConflict(fn () => $this->bookings->accept($booking));

        $this->assertNotSame('confirmed', $booking->fresh()->status);
        $this->assertSame(0, $booking->events()->where('type', 'confirmed')->count());
        $this->assertSame(0, LedgerEntry::where('booking_id', $booking->id)->count());
    }

    public function test_accept_rejects_a_request_whose_appointment_has_started(): void
    {
        $booking = $this->waiting();
        Booking::whereKey($booking->id)->update(['expires_at' => $this->at('12:00')]);
        $this->travelTo($this->at('10:00')->toMutable());

        $this->assertConflict(fn () => $this->bookings->accept($booking));

        $this->assertNotSame('confirmed', $booking->fresh()->status);
        $this->assertSame(0, $booking->events()->where('type', 'confirmed')->count());
    }

    public function test_accept_rejects_a_waiting_booking_with_released_allocations(): void
    {
        $booking = $this->waiting();
        Allocation::where('booking_id', $booking->id)->update(['status' => 'released']);

        $this->assertConflict(fn () => $this->bookings->accept($booking));

        $this->assertSame('waiting', $booking->fresh()->status);
        $this->assertSame([], $this->activeAllocations($booking));
    }

    public function test_accept_rejects_partial_allocations_for_a_multi_stage_treatment(): void
    {
        $booking = $this->bookings->book($this->spa, $this->at('16:00'), [
            ['treatment' => $this->hs->id, 'party' => 1],
        ], $this->customer);
        $booking->allocations()->orderByDesc('start_at')->first()->update(['status' => 'released']);

        $exception = $this->assertConflict(fn () => $this->bookings->accept($booking));

        $this->assertSame('allocations_missing', $exception->reason);
        $this->assertSame('waiting', $booking->fresh()->status);
        $this->assertCount(1, $this->activeAllocations($booking));
        $this->assertSame(0, $booking->events()->where('type', 'confirmed')->count());
    }

    public function test_stale_decline_cannot_change_a_confirmed_booking(): void
    {
        $booking = $this->waiting();
        $stale = $booking->fresh();
        $this->bookings->accept($booking);

        $this->assertConflict(fn () => $this->bookings->decline($stale, 'partner', 'closed'));

        $this->assertSame('confirmed', $booking->fresh()->status);
        $this->assertCount(1, $this->activeAllocations($booking));
        $this->assertSame(0, $booking->declines()->count());
    }

    public function test_stale_reschedule_cannot_reallocate_a_cancelled_booking(): void
    {
        $booking = $this->waiting();
        $this->bookings->accept($booking);
        $stale = $booking->fresh();
        $this->bookings->cancel($booking, 'admin');

        $this->assertConflict(fn () => $this->bookings->reschedule($stale, $this->at('17:00')));

        $this->assertSame('cancelled', $booking->fresh()->status);
        $this->assertSame('10:00', $booking->fresh()->start_at->format('H:i'));
        $this->assertSame([], $this->activeAllocations($booking));
        $this->assertSame(0, $booking->events()->where('type', 'rescheduled')->count());
    }

    public function test_reschedule_rechecks_opening_hours_without_releasing_the_existing_slot(): void
    {
        $booking = $this->waiting();
        $this->bookings->accept($booking);
        $allocationIds = $booking->allocations()->where('status', 'active')->pluck('id')->all();

        $exception = $this->assertConflict(fn () => $this->bookings->reschedule($booking, $this->at('14:15')));

        $this->assertSame('unavailable', $exception->reason);
        $this->assertSame('10:00', $booking->fresh()->start_at->format('H:i'));
        $this->assertSame($allocationIds, $booking->allocations()->where('status', 'active')->pluck('id')->all());
    }

    public function test_complete_requires_the_actual_end_time_and_a_fresh_confirmed_status(): void
    {
        $booking = $this->waiting();
        $this->bookings->accept($booking);
        $this->assertConflict(fn () => $this->bookings->complete($booking));
        $this->assertSame('confirmed', $booking->fresh()->status);
        $this->assertSame(0, LedgerEntry::where('booking_id', $booking->id)->count());

        $stale = $booking->fresh();
        $this->bookings->cancel($booking, 'admin');
        $this->travelTo($booking->end_at->addMinute()->toMutable());
        $this->assertConflict(fn () => $this->bookings->complete($stale));
        $this->assertSame('cancelled', $booking->fresh()->status);
        $this->assertSame(0, LedgerEntry::where('booking_id', $booking->id)->count());
    }

    public function test_acceptance_rolls_back_when_the_audit_entry_cannot_be_written(): void
    {
        $booking = $this->waiting();
        $expiry = $booking->expires_at->toDateTimeString();
        Mail::fake();
        $eventName = 'eloquent.creating: '.BookingEvent::class;
        Event::listen($eventName, function (BookingEvent $event): void {
            if ($event->type === 'confirmed') {
                throw new RuntimeException('Simulated audit write failure');
            }
        });

        try {
            $this->bookings->accept($booking);
            $this->fail('L’échec de journalisation doit annuler toute la transition.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Simulated audit write failure', $exception->getMessage());
        } finally {
            Event::forget($eventName);
        }

        $fresh = $booking->fresh();
        $this->assertSame('waiting', $fresh->status);
        $this->assertSame($expiry, $fresh->expires_at->toDateTimeString());
        $this->assertNull($fresh->confirmed_at);
        $this->assertNull($fresh->contact_revealed_at);
        $this->assertCount(1, $this->activeAllocations($fresh));
        $this->assertSame(0, $fresh->events()->where('type', 'confirmed')->count());
        Mail::assertNothingQueued();
    }

    public function test_closing_rolls_back_the_commission_if_the_audit_write_fails(): void
    {
        $booking = $this->waiting();
        $this->bookings->accept($booking);
        $this->travelTo($booking->end_at->addMinute()->toMutable());
        $eventName = 'eloquent.creating: '.BookingEvent::class;
        Event::listen($eventName, function (BookingEvent $event): void {
            if ($event->type === 'completed') {
                throw new RuntimeException('Simulated closing write failure');
            }
        });

        try {
            $this->bookings->complete($booking);
            $this->fail('La commission et la clôture doivent être annulées ensemble.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Simulated closing write failure', $exception->getMessage());
        } finally {
            Event::forget($eventName);
        }

        $this->assertSame('confirmed', $booking->fresh()->status);
        $this->assertSame(0, LedgerEntry::where('booking_id', $booking->id)->count());
        $this->assertSame(0, $booking->events()->where('type', 'completed')->count());
    }

    public function test_sold_duration_and_allocations_survive_a_later_catalogue_edit(): void
    {
        $booking = $this->submit($this->intent('10:00', [
            'treatment' => $this->hm->id, 'party' => 2, 'extras' => [$this->cr->id],
        ]))['booking'];
        $this->assertNotNull($booking);
        $originalTotal = (float) $booking->total;
        $this->hm->steps()->orderByDesc('position')->first()->update(['duration_min' => 30]);
        $this->cr->update(['extra_min' => 5, 'price' => 999]);

        $this->bookings->accept($booking);
        $this->bookings->reschedule($booking, $this->at('17:00'));

        $fresh = $booking->fresh();
        $this->assertSame(135, $fresh->duration_min);
        $this->assertSame($originalTotal, (float) $fresh->total);
        $this->assertSame('19:15', $fresh->end_at->format('H:i'));
        $ends = $fresh->allocations()->where('status', 'active')->get()->map(
            fn (Allocation $allocation) => CarbonImmutable::parse($allocation->end_at)->format('H:i'),
        );
        $this->assertCount(3, $ends);
        $this->assertSame(2, $ends->filter(fn (string $time) => $time === '19:15')->count());
    }
}
