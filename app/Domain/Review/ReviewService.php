<?php

namespace App\Domain\Review;

use App\Domain\Booking\BookingException;
use App\Domain\Media\PhotoProcessor;
use App\Jobs\SendBookingNotification;
use App\Listeners\SendBookingNotifications;
use App\Models\ActivityLog;
use App\Models\Booking;
use App\Models\Review;
use App\Models\ReviewPhoto;
use App\Models\Spa;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Avis vérifiés : invitation le lendemain d'une prestation réellement effectuée (jamais pour un no-show),
 * un seul avis par réservation, modération motivée (un avis négatif n'est jamais un motif), réponse partenaire modérée.
 */
class ReviewService
{
    /** Au-delà de ce délai après la prestation, on n'invite plus (évite d'inviter d'anciennes réservations lors d'un premier lancement). */
    public const INVITE_MAX_DAYS = 14;

    /** Événement de réservation tracé une seule fois quand l'invitation est impossible (pas d'e-mail client). */
    public const EVENT_NOT_APPLICABLE = 'review:not_applicable';

    public function isEligible(Booking $booking): bool
    {
        return $booking->status === 'completed' && ! Review::where('booking_id', $booking->id)->exists();
    }

    /** Une invitation suppose une adresse e-mail client valide ; sans elle, l'invitation est « non applicable », jamais un échec technique. */
    public function hasInvitableEmail(Booking $booking): bool
    {
        return filter_var(trim((string) $booking->email), FILTER_VALIDATE_EMAIL) !== false;
    }

    /** Crée l'invitation (statut « invited ») et envoie l'e-mail au client via une notification tracée (envoyée / échouée, reprise). */
    public function invite(Booking $booking): Review
    {
        if (! $this->isEligible($booking)) {
            throw BookingException::make('review', 'review_not_eligible', [], 409);
        }
        if (! $this->hasInvitableEmail($booking)) {
            throw BookingException::make('review', 'review_no_email', [], 409);
        }
        $review = Review::create([
            'spa_id' => $booking->spa_id,
            'booking_id' => $booking->id,
            'token' => Str::random(48),
            'author_name' => trim($booking->first_name.' '.mb_substr((string) $booking->last_name, 0, 1).'.'),
            'rating' => 0,
            'locale' => $booking->locale ?: 'fr',
            'status' => 'invited',
            'invited_at' => now(),
        ]);
        $booking->log('review:invited', 'system', ['review' => $review->id]);
        SendBookingNotifications::dispatch($booking, SendBookingNotification::REVIEW_INVITE, 'client', trim((string) $booking->email));

        return $review;
    }

    /** Invite les réservations terminées dont la prestation s'est achevée la veille ou avant (idempotent). @return list<int> */
    public function inviteDue(?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now(config('hl.timezone'));
        $due = Booking::where('status', 'completed')
            ->where('end_at', '<', $now->startOfDay())
            ->where('end_at', '>=', $now->subDays(self::INVITE_MAX_DAYS))
            ->whereDoesntHave('review')
            ->get();
        $ids = [];
        foreach ($due as $booking) {
            if (! $this->hasInvitableEmail($booking)) {
                if (! $booking->events()->where('type', self::EVENT_NOT_APPLICABLE)->exists()) {
                    $booking->log(self::EVENT_NOT_APPLICABLE, 'system', ['reason' => 'no_email']);
                }

                continue;
            }
            $ids[] = $this->invite($booking)->id;
        }

        return $ids;
    }

    /**
     * Dépôt de l'avis par le client depuis son lien unique.
     *
     * @param  array{rating: int, title?: ?string, body: string, liked?: ?string, improve?: ?string, criteria?: array<string, int>, bonus?: array<string, string>}  $data
     * @param  list<UploadedFile>  $photos
     */
    public function submit(Review $review, array $data, array $photos = []): Review
    {
        if (! $review->isInvited()) {
            throw BookingException::make('review', 'review_already_submitted', [], 409);
        }
        $rating = (int) ($data['rating'] ?? 0);
        if ($rating < 1 || $rating > 5) {
            throw BookingException::make('rating', 'review_rating_required');
        }
        $body = trim((string) ($data['body'] ?? ''));
        if (mb_strlen($body) < Review::MIN_BODY) {
            throw BookingException::make('body', 'review_body_required', ['min' => Review::MIN_BODY]);
        }
        if (count($photos) > Review::MAX_PHOTOS) {
            throw BookingException::make('photos', 'review_too_many_photos', ['max' => Review::MAX_PHOTOS]);
        }
        $criteria = [];
        foreach ($data['criteria'] ?? [] as $key => $value) {
            if (in_array($key, Review::CRITERIA, true) && (int) $value >= 1 && (int) $value <= 3) {
                $criteria[$key] = (int) $value;
            }
        }
        $bonus = [];
        foreach ($data['bonus'] ?? [] as $key => $value) {
            if (in_array($key, Review::BONUS, true) && in_array($value, ['yes', 'no'], true)) {
                $bonus[$key] = $value;
            }
        }

        $stored = [];
        foreach (array_values($photos) as $i => $file) {
            try {
                $stored[] = ['disk' => 'local', 'path' => PhotoProcessor::store($file, 'reviews', 'local'), 'sort' => $i];
            } catch (InvalidArgumentException $e) {
                foreach ($stored as $p) {
                    Storage::disk('local')->delete($p['path']);
                }
                throw new BookingException('photos', $e->getMessage());
            }
        }

        DB::transaction(function () use ($review, $rating, $body, $data, $criteria, $bonus, $stored) {
            $review->update([
                'rating' => $rating,
                'title' => Str::limit(trim((string) ($data['title'] ?? '')), 150, '') ?: null,
                'body' => $body,
                'liked' => trim((string) ($data['liked'] ?? '')) ?: null,
                'improve' => trim((string) ($data['improve'] ?? '')) ?: null,
                'criteria' => $criteria ?: null,
                'bonus' => $bonus ?: null,
                'status' => 'pending',
                'submitted_at' => now(),
            ]);
            foreach ($stored as $p) {
                $review->photos()->create($p);
            }
        });
        $review->booking?->log('review:submitted', 'client', ['review' => $review->id, 'rating' => $rating, 'photos' => count($stored)]);

        return $review->refresh();
    }

    /** Publication ou refus motivé par l'administration ; la note seule n'est jamais un motif admissible. */
    public function moderate(Review $review, User $admin, string $status, ?string $reason = null, ?string $note = null): Review
    {
        if (! in_array($status, ['published', 'rejected'], true) || $review->isInvited()) {
            throw BookingException::make('status', 'invalid', [], 422);
        }
        if ($status === 'rejected') {
            if (! in_array($reason, Review::REJECTION_REASONS, true)) {
                throw BookingException::make('reason', 'review_rejection_reason_required');
            }
            if ($reason === 'other' && mb_strlen(trim((string) $note)) < 10) {
                throw BookingException::make('note', 'review_rejection_note_required');
            }
        }

        DB::transaction(function () use ($review, $admin, $status, $reason, $note) {
            $review->update([
                'status' => $status,
                'published_at' => $status === 'published' ? ($review->published_at ?? now()) : null,
                'rejection_reason' => $status === 'rejected' ? $reason : null,
                'rejection_note' => $status === 'rejected' ? (trim((string) $note) ?: null) : null,
                'moderated_by' => $admin->id,
                'moderated_at' => now(),
            ]);
            foreach ($review->photos as $photo) {
                $status === 'published' ? $this->publishPhoto($photo) : $this->privatisePhoto($photo);
            }
            $this->recomputeRating($review->spa);
        });
        $review->booking?->log('review:'.$status, 'admin', array_filter(['review' => $review->id, 'reason' => $reason]));
        ActivityLog::record('review.'.$status, $review, array_filter(['spa' => $review->spa->name, 'rating' => $review->rating, 'reason' => $reason]), 'admin');

        return $review->refresh();
    }

    /** Réponse de l'établissement à un avis publié : en attente de modération, invisible du public jusqu'à publication. */
    /**
     * Première réponse (ou nouvelle réponse après refus) : soumise à vérification avant publication.
     * Modification d'une réponse en attente : le texte est remplacé. Modification d'une réponse publiée :
     * conservée en brouillon jusqu'à vérification, l'ancienne réponse reste publiée entre-temps.
     */
    public function reply(Review $review, ?User $partner, string $text): Review
    {
        if (! $review->canReply() && ! $review->canEditReply()) {
            throw BookingException::make('reply', 'review_reply_not_allowed', [], 409);
        }
        $text = trim($text);
        if (mb_strlen($text) < Review::MIN_BODY) {
            throw BookingException::make('reply', 'review_body_required', ['min' => Review::MIN_BODY]);
        }
        if ($review->replyPublished()) {
            $review->update(['reply_draft' => $text, 'reply_draft_status' => 'pending', 'reply_draft_rejection_reason' => null, 'reply_draft_at' => now()]);
            $review->booking?->log('review:reply_edit_submitted', 'partner', ['review' => $review->id]);
            ActivityLog::record('review.reply_edit_submitted', $review, ['spa' => $review->spa->name], 'partner');

            return $review;
        }
        $review->update(['reply' => $text, 'reply_status' => 'pending', 'reply_at' => now(), 'reply_rejection_reason' => null, 'reply_moderated_at' => null]);
        $review->booking?->log('review:reply_submitted', 'partner', ['review' => $review->id]);
        ActivityLog::record('review.reply_submitted', $review, ['spa' => $review->spa->name], 'partner');

        return $review;
    }

    public function moderateReply(Review $review, User $admin, string $status, ?string $reason = null): Review
    {
        if (! in_array($status, ['published', 'rejected'], true) || $review->reply === null) {
            throw BookingException::make('status', 'invalid', [], 422);
        }
        if ($status === 'rejected' && ! in_array($reason, Review::REJECTION_REASONS, true)) {
            throw BookingException::make('reason', 'review_rejection_reason_required');
        }
        if ($review->hasPendingDraft()) {
            $review->update($status === 'published'
                ? ['reply' => $review->reply_draft, 'reply_status' => 'published', 'reply_at' => $review->reply_draft_at, 'reply_rejection_reason' => null, 'reply_moderated_at' => now(),
                    'reply_draft' => null, 'reply_draft_status' => null, 'reply_draft_rejection_reason' => null, 'reply_draft_at' => null]
                : ['reply_draft_status' => 'rejected', 'reply_draft_rejection_reason' => $reason]);
            $review->booking?->log('review:reply_edit_'.$status, 'admin', array_filter(['review' => $review->id, 'reason' => $reason]));
            ActivityLog::record('review.reply_edit_'.$status, $review, array_filter(['spa' => $review->spa->name, 'reason' => $reason]), 'admin');

            return $review;
        }
        $review->update(['reply_status' => $status, 'reply_rejection_reason' => $status === 'rejected' ? $reason : null, 'reply_moderated_at' => now()]);
        $review->booking?->log('review:reply_'.$status, 'admin', array_filter(['review' => $review->id, 'reason' => $reason]));
        ActivityLog::record('review.reply_'.$status, $review, array_filter(['spa' => $review->spa->name, 'reason' => $reason]), 'admin');

        return $review;
    }

    public function recomputeRating(Spa $spa): void
    {
        $q = $spa->reviews()->published();
        $spa->update([
            'rating' => $q->exists() ? round((float) $q->avg('rating'), 2) : null,
            'reviews_count' => $q->count(),
        ]);
    }

    private function publishPhoto(ReviewPhoto $photo): void
    {
        if ($photo->isPublic()) {
            return;
        }
        $content = Storage::disk('local')->get($photo->path);
        if ($content !== null) {
            Storage::disk('public')->put($photo->path, $content, 'public');
            Storage::disk('local')->delete($photo->path);
        }
        $photo->update(['disk' => 'public']);
    }

    private function privatisePhoto(ReviewPhoto $photo): void
    {
        if (! $photo->isPublic()) {
            return;
        }
        $content = Storage::disk('public')->get($photo->path);
        if ($content !== null) {
            Storage::disk('local')->put($photo->path, $content);
            Storage::disk('public')->delete($photo->path);
        }
        $photo->update(['disk' => 'local']);
    }
}
