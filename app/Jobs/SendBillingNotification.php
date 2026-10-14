<?php

namespace App\Jobs;

use App\Mail\BillingMail;
use App\Models\BillingNotification;
use App\Models\CommissionDispute;
use App\Models\Partner;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Mail;
use Throwable;

/** Envoi d'une notification financière ; l'état est écrit après l'envoi réel, jamais avant. */
class SendBillingNotification implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $backoff = 60;

    public function __construct(public int $notificationId) {}

    public function handle(): void
    {
        $n = BillingNotification::with('notifiable')->find($this->notificationId);
        if (! $n || $n->status === 'sent') {
            return;
        }
        $partner = $n->notifiable instanceof Partner ? $n->notifiable : ($n->notifiable instanceof CommissionDispute ? $n->notifiable->partner : null);
        $locale = $n->audience === 'partner' ? ($partner?->user?->locale ?: 'fr') : 'fr';
        try {
            Mail::to($n->recipient)->sendNow((new BillingMail($n))->locale($locale));
        } catch (Throwable $e) {
            $n->update(['status' => 'failed', 'attempts' => $n->attempts + 1, 'failed_at' => now(), 'error' => mb_substr($e->getMessage(), 0, 500)]);
            throw $e;
        }
        $n->update(['status' => 'sent', 'attempts' => $n->attempts + 1, 'sent_at' => now(), 'error' => null]);
    }

    public function failed(Throwable $e): void
    {
        BillingNotification::whereKey($this->notificationId)->where('status', '!=', 'sent')
            ->update(['status' => 'failed', 'failed_at' => now(), 'error' => mb_substr($e->getMessage(), 0, 500)]);
    }
}
