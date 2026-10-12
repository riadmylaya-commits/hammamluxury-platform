<?php

namespace App\Console\Commands;

use App\Domain\Review\ReviewService;
use Illuminate\Console\Command;

class SendReviewInvitations extends Command
{
    protected $signature = 'hl:review-invitations';

    protected $description = 'Invite les clients des prestations terminées la veille à donner leur avis (un seul avis par réservation)';

    public function handle(ReviewService $reviews): int
    {
        $ids = $reviews->inviteDue();
        $this->info(count($ids).' invitation(s) envoyée(s)'.($ids ? ' : '.implode(', ', $ids) : ''));

        return self::SUCCESS;
    }
}
