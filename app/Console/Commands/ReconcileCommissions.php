<?php

namespace App\Console\Commands;

use App\Domain\Booking\BookingMutation;
use App\Domain\Booking\CommissionLedger;
use App\Models\Booking;
use App\Models\LedgerEntry;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

/** Lecture seule par défaut. --apply nécessite une décision comptable et un environnement autorisé. */
class ReconcileCommissions extends Command
{
    protected $signature = 'hl:reconcile-commissions {--booking=* : Limit to booking IDs} {--apply : Append correction entries under the booking lock}';

    protected $description = 'Compare accrued commissions with the ledger; report only unless --apply is explicitly provided';

    public function handle(BookingMutation $mutations, CommissionLedger $ledger): int
    {
        $ids = $this->option('booking');
        foreach ($ids as $id) {
            if (! ctype_digit((string) $id) || (int) $id < 1) {
                $this->error('Each --booking must be a positive booking ID.');

                return self::FAILURE;
            }
        }
        $apply = (bool) $this->option('apply');
        if ($apply && ! Schema::hasColumn('ledger_entries', 'idempotency_key')) {
            $this->error('Apply the commission integrity migration first. Legacy duplicates require manual reconciliation.');

            return self::FAILURE;
        }
        // Ne jamais compenser automatiquement un doublon qui nécessite d'abord une décision comptable.
        $duplicates = LedgerEntry::where('type', 'commission')->whereNotNull('booking_id')
            ->when($ids !== [], fn ($query) => $query->whereIn('booking_id', $ids))
            ->select('booking_id')->groupBy('booking_id')->havingRaw('COUNT(*) > 1')->pluck('booking_id');
        if ($duplicates->isNotEmpty()) {
            $this->error('Duplicate legacy commissions for booking IDs: '.$duplicates->implode(', ').'. Reconcile manually; no entries changed.');

            return self::FAILURE;
        }

        $mismatches = 0;
        $corrections = 0;
        $query = Booking::query()->when($ids !== [], fn ($query) => $query->whereIn('id', $ids));
        $query->orderBy('id')->chunkById(200, function ($bookings) use ($apply, $mutations, $ledger, &$mismatches, &$corrections) {
            $balances = LedgerEntry::whereIn('booking_id', $bookings->modelKeys())->whereIn('type', LedgerEntry::COMMISSION_TYPES)
                ->selectRaw('booking_id, SUM(amount) AS balance')->groupBy('booking_id')->pluck('balance', 'booking_id');
            foreach ($bookings as $booking) {
                $actualCents = (int) round((float) ($balances[$booking->id] ?? 0) * 100);
                $expectedCents = (int) round($booking->commissionDue() * 100);
                if ($actualCents === $expectedCents) {
                    continue;
                }
                $mismatches++;
                $this->line($booking->reference.' #'.$booking->id.' '.$booking->status.'/'.$booking->payment_status
                    .' ledger='.number_format($actualCents / 100, 2, '.', '')
                    .' due='.number_format($expectedCents / 100, 2, '.', '').' '.$booking->currency);
                if ($apply) {
                    // Relire et recalculer le solde sous verrou ; le rapport préalable peut être périmé.
                    $entry = $mutations->forBooking($booking, fn (Booking $fresh) => $ledger->sync($fresh, 'system'));
                    if ($entry !== null) {
                        $corrections++;
                    }
                }
            }
        });
        $orphans = $ids === [] ? LedgerEntry::whereIn('type', LedgerEntry::COMMISSION_TYPES)->whereNull('booking_id')->count() : 0;
        if ($orphans > 0) {
            $this->warn($orphans.' commission entries have no booking; inspect their historical references manually.');
        }
        $this->info($apply ? $corrections.' linked correction entries appended.' : 'READ ONLY: '.$mismatches.' mismatches; no data changed.');

        return ($orphans > 0 || (! $apply && $mismatches > 0)) ? self::FAILURE : self::SUCCESS;
    }
}
