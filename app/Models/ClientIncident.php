<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Signalement d'un comportement client par l'établissement après le rendez-vous.
 * Conservé dans l'historique du client (empreinte téléphone / e-mail), visible de l'administration seulement ; aucune sanction automatique.
 */
class ClientIncident extends Model
{
    public const CATEGORIES = ['inappropriate_behaviour', 'aggression', 'damage', 'fraud', 'payment_issue', 'other'];

    public const STATUSES = ['open', 'reviewed', 'dismissed'];

    protected $guarded = [];

    protected $casts = ['reviewed_at' => 'datetime'];

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    public function spa(): BelongsTo
    {
        return $this->belongsTo(Spa::class);
    }

    public function reporter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reported_by');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    /** Nombre d'établissements distincts ayant signalé ce même client. */
    public function otherSpasCount(): int
    {
        return static::where('client_key', $this->client_key)->where('spa_id', '!=', $this->spa_id)->distinct('spa_id')->count('spa_id');
    }
}
