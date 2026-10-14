<?php

namespace App\Domain\Billing;

use App\Jobs\SendBillingNotification;
use App\Models\BillingNotification;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Notifications financières traçables (relevé disponible, contestation reçue / décidée) : une ligne par destinataire,
 * clé de déduplication unique, envoi par job après validation de la transaction, reprise sans doublon.
 */
class BillingNotifier
{
    /** @param  array<string, mixed>  $payload */
    public function dispatch(Model $notifiable, string $event, string $audience, string $recipient, array $payload = [], ?string $dedupeSuffix = null): ?BillingNotification
    {
        $key = implode(':', array_filter([$event, $notifiable->getMorphClass(), $notifiable->getKey(), $audience, $dedupeSuffix], fn ($v) => $v !== null && $v !== ''));
        try {
            $n = BillingNotification::create([
                'dedupe_key' => mb_substr($key, 0, 120),
                'notifiable_type' => $notifiable->getMorphClass(),
                'notifiable_id' => $notifiable->getKey(),
                'event' => $event,
                'audience' => $audience,
                'recipient' => $recipient,
                'payload' => $payload ?: null,
            ]);
        } catch (UniqueConstraintViolationException) {
            return null;
        }
        DB::afterCommit(function () use ($n) {
            $n->update(['status' => 'queued', 'queued_at' => now()]);
            SendBillingNotification::dispatch($n->id);
        });

        return $n;
    }

    /** Remet en file les notifications échouées ou perdues ; retourne le nombre relancé. */
    public function retry(int $maxAttempts = 5): int
    {
        $stuck = BillingNotification::where(fn ($q) => $q->where('status', 'failed')
            ->orWhere(fn ($q) => $q->where('status', 'planned')->where('created_at', '<', now()->subMinutes(10)))
            ->orWhere(fn ($q) => $q->where('status', 'queued')->where('queued_at', '<', now()->subHours(1))))
            ->where('attempts', '<', $maxAttempts)->get();
        foreach ($stuck as $n) {
            $n->update(['status' => 'queued', 'queued_at' => now()]);
            SendBillingNotification::dispatch($n->id);
        }

        return $stuck->count();
    }
}
