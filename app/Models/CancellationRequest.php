<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Demande d'annulation d'une réservation confirmée émise par le partenaire ; la décision finale revient à HammamLuxury. */
class CancellationRequest extends Model
{
    public const STATUSES = ['pending', 'accepted', 'refused', 'rescheduled', 'closed'];

    /** Motifs exceptionnels admis ; `other` impose une explication détaillée. */
    public const REASON_CODES = ['administrative_closure', 'technical_failure', 'safety_issue', 'staff_unavailable', 'force_majeure', 'other'];

    protected $guarded = [];

    protected $casts = [
        'client_notified_at' => 'datetime',
        'client_responded_at' => 'datetime',
        'decided_at' => 'datetime',
        'proposed_start_at' => 'datetime',
        'proposed_at' => 'datetime',
        'proposal_responded_at' => 'datetime',
    ];

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    public function spa(): BelongsTo
    {
        return $this->belongsTo(Spa::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    /** Une nouvelle date a été proposée au client et attend sa réponse. */
    public function hasOpenProposal(): bool
    {
        return $this->isPending() && $this->proposed_start_at !== null && $this->proposal_response === null;
    }

    /** Étape courante du circuit : received → client_notified → client_response → (proposed) → decided. */
    public function step(): string
    {
        return match (true) {
            $this->status !== 'pending' => $this->status,
            $this->hasOpenProposal() => 'proposed',
            $this->proposal_response === 'refused' => 'proposal_refused',
            $this->client_response !== null => 'client_'.$this->client_response,
            $this->client_notified_at !== null => 'client_notified',
            default => 'received',
        };
    }
}
