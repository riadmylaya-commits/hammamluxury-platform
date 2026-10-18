<?php

namespace App\Console\Commands;

use App\Domain\Review\ReviewService;
use App\Models\BookingEvent;
use Illuminate\Console\Command;

class SendReviewInvitations extends Command
{
    protected $signature = 'hl:review-invitations';

    protected $description = 'Invite les clients des prestations terminées la veille à donner leur avis (un seul avis par réservation)';

    public function handle(ReviewService $reviews): int
    {
        $ids = $reviews->inviteDue();
        $this->info(count($ids).' invitation(s) envoyée(s)'.($ids ? ' : '.implode(', ', $ids) : ''));
        $skipped = BookingEvent::where('type', ReviewService::EVENT_NOT_APPLICABLE)->whereDate('created_at', now(config('hl.timezone'))->toDateString())->count();
        if ($skipped > 0) {
            $this->line($skipped.' réservation(s) sans e-mail client : invitation non applicable (tracée, aucun envoi).');
        }

        return self::SUCCESS;
    }
}
