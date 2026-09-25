<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/** Journal des actions importantes (admin, partenaire, système). Écriture via ActivityLog::record(). */
class ActivityLog extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = [];

    protected $casts = ['properties' => 'array', 'created_at' => 'datetime'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    /** @param  array<string, mixed>  $properties */
    public static function record(string $action, ?Model $subject = null, array $properties = [], ?string $panel = null): self
    {
        $user = auth()->user();
        $request = request();

        return self::create([
            'user_id' => $user?->getAuthIdentifier(),
            'panel' => $panel ?? ($user instanceof User ? $user->role : (app()->runningInConsole() ? 'system' : 'site')),
            'action' => $action,
            'subject_type' => $subject?->getMorphClass(),
            'subject_id' => $subject?->getKey(),
            'properties' => $properties ?: null,
            'ip' => app()->runningInConsole() ? null : $request->ip(),
        ]);
    }
}
