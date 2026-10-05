<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Avis vérifié : un seul par réservation réellement effectuée (statut « terminée »), déposé via un lien unique,
 * publié après modération HammamLuxury ; l'établissement peut répondre, sa réponse étant modérée de la même façon.
 */
class Review extends Model
{
    public const STATUSES = ['invited', 'pending', 'published', 'rejected'];

    public const CRITERIA = ['welcome', 'cleanliness', 'treatment_quality', 'comfort', 'value', 'ambiance'];

    public const BONUS = ['as_described', 'welcomed', 'recommend'];

    /** Motifs de refus admissibles : jamais « avis négatif ». */
    public const REJECTION_REASONS = ['insults', 'fraud_offtopic', 'personal_data', 'inappropriate_photo', 'other'];

    public const MIN_BODY = 20;

    public const MAX_PHOTOS = 5;

    protected $guarded = [];

    protected $casts = [
        'criteria' => 'array', 'bonus' => 'array',
        'invited_at' => 'datetime', 'submitted_at' => 'datetime', 'published_at' => 'datetime', 'moderated_at' => 'datetime',
        'reply_at' => 'datetime', 'reply_moderated_at' => 'datetime',
    ];

    public function spa(): BelongsTo
    {
        return $this->belongsTo(Spa::class);
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    public function photos(): HasMany
    {
        return $this->hasMany(ReviewPhoto::class)->orderBy('sort');
    }

    public function moderator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'moderated_by');
    }

    public function scopePublished(Builder $q): Builder
    {
        return $q->where('status', 'published');
    }

    public function isInvited(): bool
    {
        return $this->status === 'invited';
    }

    public function isPublished(): bool
    {
        return $this->status === 'published';
    }

    public function isVerified(): bool
    {
        return $this->booking_id !== null;
    }

    public function replyPublished(): bool
    {
        return $this->reply !== null && $this->reply_status === 'published';
    }

    /** L'établissement peut répondre à un avis publié, tant qu'aucune réponse n'est en attente ou publiée. */
    public function canReply(): bool
    {
        return $this->isPublished() && ($this->reply === null || $this->reply_status === 'rejected');
    }

    public function isNegative(): bool
    {
        return (int) $this->rating <= 3;
    }
}
