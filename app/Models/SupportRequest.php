<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Demande d'assistance écrite d'un client (formulaire Contact) ou d'un partenaire (espace partenaire). */
class SupportRequest extends Model
{
    public const STATUSES = ['new', 'in_progress', 'resolved'];

    protected $guarded = [];

    protected $casts = ['resolved_at' => 'datetime'];

    public function spa(): BelongsTo
    {
        return $this->belongsTo(Spa::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(SupportMessage::class)->orderBy('id');
    }

    public static function statusColor(string $status): string
    {
        return match ($status) {
            'new' => 'danger', 'in_progress' => 'warning', default => 'success'
        };
    }
}
