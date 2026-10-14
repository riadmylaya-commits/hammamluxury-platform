<?php

namespace App\Domain\Billing;

use App\Models\ActivityLog;
use App\Models\Booking;
use App\Models\CommissionDispute;
use App\Models\LedgerEntry;
use App\Models\Partner;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Contestations de commission avant émission. Aucune écriture du grand livre n'est modifiée : une contestation ouverte
 * retire la réservation du relevé facturable ; après décision, toute correction passe par les outils de réservation
 * existants (annulation, frais, remboursement) qui ajoutent une écriture corrective en ajout seul.
 */
class DisputeService
{
    public function __construct(private readonly StatementBuilder $statements, private readonly BillingNotifier $notifier) {}

    /** Fenêtre normale : du 1er au `dispute_until_day` du mois suivant la période (ou pendant le mois en cours). Au-delà : demande de correction. */
    public function kindFor(Statement $statement, ?CarbonImmutable $now = null): string
    {
        return $statement->disputeWindowOpen($now) ? 'dispute' : 'correction_request';
    }

    /**
     * @throws DisputeException
     */
    public function open(Partner $partner, Booking $booking, ?User $by, string $reasonCode, string $comment, ?string $evidencePath = null, ?CarbonImmutable $now = null): CommissionDispute
    {
        if ($booking->spa->partner_id !== $partner->id) {
            throw new DisputeException('not_owner');
        }
        if (! in_array($reasonCode, CommissionDispute::REASONS, true)) {
            throw new DisputeException('invalid_reason');
        }
        if (trim($comment) === '') {
            throw new DisputeException('comment_required');
        }
        if ($booking->source === 'partner') {
            throw new DisputeException('offline_booking');
        }
        $entry = LedgerEntry::where('booking_id', $booking->id)->whereIn('type', LedgerEntry::COMMISSION_TYPES)
            ->where('billing_scope', LedgerEntry::SCOPE_BILLABLE)->orderBy('id')->first();
        if ($entry === null) {
            throw new DisputeException('not_billable');
        }
        if (CommissionDispute::open()->where('booking_id', $booking->id)->exists()) {
            throw new DisputeException('already_open');
        }
        $statement = $this->statements->build($partner, $this->statements->originPeriod($entry), $now);
        $kind = $this->kindFor($statement, $now);

        try {
            $dispute = DB::transaction(function () use ($partner, $booking, $entry, $by, $reasonCode, $comment, $evidencePath, $kind) {
                $dispute = CommissionDispute::create([
                    'partner_id' => $partner->id,
                    'spa_id' => $booking->spa_id,
                    'booking_id' => $booking->id,
                    'ledger_entry_id' => $entry->id,
                    'period' => $this->statements->originPeriod($entry)->format('Y-m'),
                    'kind' => $kind,
                    'reason_code' => $reasonCode,
                    'comment' => trim($comment),
                    'evidence_path' => $evidencePath,
                    'status' => 'open',
                    'open_booking_id' => $booking->id,
                    'opened_by' => $by?->id,
                ]);
                ActivityLog::record('billing.dispute_opened', $dispute, ['booking' => $booking->reference, 'kind' => $kind, 'reason' => $reasonCode, 'evidence' => (bool) $evidencePath], $by?->role ?? 'partner');
                $booking->log('commission:disputed', 'partner', ['dispute' => $dispute->id, 'kind' => $kind, 'reason' => $reasonCode]);
                $this->notifier->dispatch($dispute, 'dispute_opened', 'admin', (string) config('hl.alert_email'), ['booking' => $booking->reference]);

                return $dispute;
            });
        } catch (UniqueConstraintViolationException) {
            throw new DisputeException('already_open');
        }

        return $dispute;
    }

    /**
     * Décision motivée de HammamLuxury ; ne touche jamais au grand livre.
     *
     * @throws DisputeException
     */
    public function decide(CommissionDispute $dispute, User $admin, string $decision, string $note): CommissionDispute
    {
        if (! in_array($decision, ['accepted', 'rejected'], true)) {
            throw new DisputeException('invalid_decision');
        }
        if (trim($note) === '') {
            throw new DisputeException('note_required');
        }

        return DB::transaction(function () use ($dispute, $admin, $decision, $note) {
            $locked = CommissionDispute::whereKey($dispute->id)->lockForUpdate()->first();
            if ($locked === null || ! $locked->isOpen()) {
                throw new DisputeException('not_open');
            }
            $locked->update(['status' => $decision, 'open_booking_id' => null, 'decided_by' => $admin->id, 'decided_at' => now(), 'decision_note' => trim($note),
                'correction_status' => $decision === 'accepted' ? CommissionDispute::CORRECTION_PENDING : null]);
            ActivityLog::record('billing.dispute_decided', $locked, ['decision' => $decision, 'booking' => $locked->booking?->reference], 'admin');
            $locked->booking?->log('commission:dispute_'.$decision, 'admin', ['dispute' => $locked->id]);
            $recipient = $locked->partner->billing_email ?: $locked->partner->user?->email;
            if ($recipient) {
                $this->notifier->dispatch($locked, 'dispute_decided', 'partner', $recipient, ['decision' => $decision]);
            }

            return $locked->fresh();
        });
    }

    /**
     * Régularisation d'une contestation acceptée : l'Admin désigne l'écriture corrective déjà passée au grand livre (contre-écriture
     * ou ajustement, ajout seul) pour la même réservation. Aucune écriture n'est créée ici.
     *
     * @throws DisputeException
     */
    public function settle(CommissionDispute $dispute, User $admin, LedgerEntry $correction, string $note = ''): CommissionDispute
    {
        return DB::transaction(function () use ($dispute, $admin, $correction, $note) {
            $locked = CommissionDispute::whereKey($dispute->id)->lockForUpdate()->first();
            if ($locked === null || ! $locked->needsCorrection()) {
                throw new DisputeException('not_pending_correction');
            }
            if ($correction->booking_id !== $locked->booking_id || ! in_array($correction->type, ['commission_reversal', 'commission_adjust'], true)
                || $correction->created_at->lt($locked->decided_at) || CommissionDispute::where('correction_entry_id', $correction->id)->exists()) {
                throw new DisputeException('correction_entry_invalid');
            }
            $locked->update(['correction_status' => CommissionDispute::CORRECTION_SETTLED, 'correction_entry_id' => $correction->id,
                'settled_by' => $admin->id, 'settled_at' => now(), 'settlement_note' => trim($note) ?: null]);
            ActivityLog::record('billing.dispute_settled', $locked, ['entry' => $correction->id, 'amount' => $correction->amount, 'booking' => $locked->booking?->reference], 'admin');
            $locked->booking?->log('commission:dispute_settled', 'admin', ['dispute' => $locked->id, 'entry' => $correction->id]);

            return $locked->fresh();
        });
    }
}
