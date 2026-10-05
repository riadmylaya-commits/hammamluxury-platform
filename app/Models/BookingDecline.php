<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Refus structuré d'une demande par l'établissement : motif obligatoire, explication interne, auteur,
 * et constat du moteur (« y avait-il encore de la place ? ») pour repérer les plannings mal tenus.
 * Visible de l'administration et de l'établissement ; jamais du client.
 */
class BookingDecline extends Model
{
    public const REASONS = ['full', 'closed', 'staff_unavailable', 'treatment_unavailable', 'client_request', 'other'];

    protected $guarded = [];

    protected $casts = ['start_at' => 'datetime', 'engine_available' => 'bool'];

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    public function spa(): BelongsTo
    {
        return $this->belongsTo(Spa::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'declined_by');
    }

    /** Refus « plus de place » alors que le moteur avait encore de la capacité : planning ou ressources probablement non tenus à jour. */
    public function isSuspicious(): bool
    {
        return $this->reason === 'full' && $this->engine_available === true;
    }
}
