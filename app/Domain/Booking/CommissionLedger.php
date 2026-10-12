<?php

namespace App\Domain\Booking;

use App\Models\Booking;
use App\Models\LedgerEntry;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Grand livre de commissions en ajout seul. Appeler sous BookingMutation::forBooking :
 * même verrou établissement, verrou de ligne et transaction que le statut et ses allocations.
 */
class CommissionLedger
{
    public function sync(Booking $booking, string $actor): ?LedgerEntry
    {
        if (DB::transactionLevel() < 1) {
            throw new LogicException('CommissionLedger requires the booking mutation transaction.');
        }

        $entries = LedgerEntry::where('booking_id', $booking->id)
            ->whereIn('type', LedgerEntry::COMMISSION_TYPES)->orderBy('id')->get();
        $currentCents = $entries->sum(fn (LedgerEntry $entry) => (int) round($entry->amount * 100));
        $targetCents = (int) round($booking->commissionDue() * 100);
        $deltaCents = $targetCents - $currentCents;
        if ($deltaCents === 0) {
            return null;
        }

        $previous = $entries->last();
        $key = 'commission:booking:'.$booking->id.':after:'.($previous?->id ?? 'initial');
        $type = $previous === null ? 'commission' : ($deltaCents < 0 ? 'commission_reversal' : 'commission_adjust');

        // La clé UNIQUE en base complète le verrou : rejouer le même état n'ajoute pas de dette.
        return LedgerEntry::firstOrCreate(['idempotency_key' => $key], [
            'booking_id' => $booking->id,
            'booking_reference' => $booking->reference,
            'partner_id' => $booking->spa->partner_id,
            'type' => $type,
            'amount' => $deltaCents / 100,
            'currency' => $booking->currency,
            'corrects_entry_id' => $previous?->id,
            'actor' => $actor,
            'note' => 'Commission '.$booking->reference.' : '.$booking->status.' / '.$booking->payment_status
                .' ; solde '.number_format($currentCents / 100, 2, '.', '').' → '.number_format($targetCents / 100, 2, '.', ''),
        ]);
    }
}
