<?php

namespace Tests\Engine;

use App\Domain\Review\ReviewService;
use App\Filament\Admin\Resources\ReviewInvitationResource;
use App\Filament\Admin\Resources\ReviewInvitationResource\Pages\ListReviewInvitations;
use App\Jobs\SendBookingNotification;
use App\Mail\ReviewInviteMail;
use App\Models\Booking;
use App\Models\BookingNotification;
use App\Models\Review;
use App\Models\User;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;

/**
 * Onglet Admin « Invitations aux avis » (lecture seule) : statut dérivé par réservation terminée — pas encore due,
 * envoyée (livraison réelle tracée), échec d'envoi, avis à modérer / publié / refusé, non applicable (pas d'e-mail), hors délai —
 * filtres établissement / dates / statut / e-mail, accès réservé à l'Admin, aucune écriture en base à l'affichage.
 */
class ReviewInvitationsAdminTest extends BookingFlowTestCase
{
    private User $admin;

    private ReviewService $reviews;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('fr');
        $this->reviews = app(ReviewService::class);
        $this->admin = User::create(['name' => 'Admin', 'email' => 'admin@example.test', 'password' => 'secret-test', 'role' => 'admin', 'email_verified_at' => now()]);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function completed(string $time, bool $withEmail = true): Booking
    {
        CarbonImmutable::setTestNow();
        $b = $this->submit($this->intent($time, ['treatment' => $this->m->id, 'party' => 1]))['booking'];
        Mail::fake();
        $this->bookings->accept($b, 'partner');
        CarbonImmutable::setTestNow($b->end_at->addHours(5));
        $this->bookings->completePast();
        if (! $withEmail) {
            Booking::whereKey($b->id)->update(['email' => '']); // réservation saisie sans adresse (colonne non nulle)
        }

        return $b->fresh();
    }

    private function statusOf(Booking $b): string
    {
        return ReviewInvitationResource::getEloquentQuery()->whereKey($b->id)->firstOrFail()->invitation_status;
    }

    public function test_invitation_is_sent_through_traceable_notification_and_tracked_as_really_sent(): void
    {
        $b = $this->completed('10:00');
        Mail::fake();
        CarbonImmutable::setTestNow($b->end_at->addDay()->setTime(10, 0));

        $ids = $this->reviews->inviteDue();

        $this->assertCount(1, $ids);
        Mail::assertSent(ReviewInviteMail::class, fn ($m) => $m->hasTo('client@example.test'));
        $n = BookingNotification::where('booking_id', $b->id)->where('event', SendBookingNotification::REVIEW_INVITE)->firstOrFail();
        $this->assertSame('client', $n->audience);
        $this->assertSame('sent', $n->status, 'L’état « envoyée » est écrit après la remise réelle');
        $this->assertNotNull($n->sent_at);
        $this->assertSame(1, $b->events()->where('type', 'notified:review_invite')->count());
        $this->assertSame('sent', $this->statusOf($b));

        $this->assertSame([], $this->reviews->inviteDue(), 'Relance idempotente');
        $this->assertSame(1, BookingNotification::where('booking_id', $b->id)->where('event', SendBookingNotification::REVIEW_INVITE)->count(), 'Une seule trace d’invitation par réservation');
    }

    public function test_smtp_failure_is_shown_as_send_failed_not_as_sent(): void
    {
        $b = $this->completed('10:00');
        Mail::fake();
        CarbonImmutable::setTestNow($b->end_at->addDay()->setTime(10, 0));
        $this->reviews->inviteDue();
        $n = BookingNotification::where('booking_id', $b->id)->where('event', SendBookingNotification::REVIEW_INVITE)->firstOrFail();
        $n->update(['status' => 'failed', 'failed_at' => now(), 'error' => 'SMTP 550 mailbox unavailable']);

        $row = ReviewInvitationResource::getEloquentQuery()->whereKey($b->id)->firstOrFail();
        $this->assertSame('send_failed', $row->invitation_status);
        $this->assertSame('SMTP 550 mailbox unavailable', $row->notif_error);
        $this->assertSame('invited', $row->review->status, 'L’avis reste « invité » : le lien d’avis est valable si le client le reçoit par un autre canal');
    }

    public function test_every_derived_status_is_classified(): void
    {
        $notDue = $this->completed('09:00');
        $noEmail = $this->completed('10:00', false);
        $withReview = $this->completed('11:00');
        $untracked = $this->completed('12:00');
        $expired = $this->completed('17:00');

        CarbonImmutable::setTestNow($notDue->end_at->addDay()->setTime(10, 0));
        Mail::fake();
        $this->reviews->inviteDue();

        $this->assertSame('not_applicable', $this->statusOf($noEmail));
        $this->assertSame(1, $noEmail->events()->where('type', ReviewService::EVENT_NOT_APPLICABLE)->count());
        $this->assertSame(0, BookingNotification::where('booking_id', $noEmail->id)->where('event', SendBookingNotification::REVIEW_INVITE)->count(), 'Aucune invitation sans e-mail');

        $review = Review::where('booking_id', $withReview->id)->firstOrFail();
        $review->update(['status' => 'pending', 'rating' => 5, 'body' => str_repeat('Très bon moment. ', 3), 'submitted_at' => now()]);
        $this->assertSame('pending', $this->statusOf($withReview));
        $review->update(['status' => 'published', 'published_at' => now()]);
        $this->assertSame('published', $this->statusOf($withReview));
        $review->update(['status' => 'rejected']);
        $this->assertSame('rejected', $this->statusOf($withReview));

        BookingNotification::where('booking_id', $untracked->id)->where('event', SendBookingNotification::REVIEW_INVITE)->delete(); // invitation antérieure au suivi de livraison
        $this->assertSame('invited_untracked', $this->statusOf($untracked));

        Review::where('booking_id', $expired->id)->delete();
        BookingNotification::where('booking_id', $expired->id)->where('event', SendBookingNotification::REVIEW_INVITE)->delete();
        Booking::whereKey($expired->id)->update(['end_at' => CarbonImmutable::now()->subDays(ReviewService::INVITE_MAX_DAYS + 2), 'start_at' => CarbonImmutable::now()->subDays(ReviewService::INVITE_MAX_DAYS + 2)->subHour()]);
        $this->assertSame('expired', $this->statusOf($expired));

        Booking::whereKey($notDue->id)->update(['end_at' => CarbonImmutable::now()->subHour(), 'start_at' => CarbonImmutable::now()->subHours(2)]);
        Review::where('booking_id', $notDue->id)->delete();
        BookingNotification::where('booking_id', $notDue->id)->where('event', SendBookingNotification::REVIEW_INVITE)->delete();
        $this->assertSame('not_due', $this->statusOf($notDue));

        $this->assertSame('sent', $this->statusOf($this->completedWithSent()));
    }

    private function completedWithSent(): Booking
    {
        $b = $this->completed('18:00');
        CarbonImmutable::setTestNow($b->end_at->addDay()->setTime(10, 0));
        Mail::fake();
        $this->reviews->inviteDue();

        return $b;
    }

    public function test_admin_page_tabs_and_filters_are_read_only(): void
    {
        $noEmail = $this->completed('10:00', false);
        $ok = $this->completed('11:00');
        CarbonImmutable::setTestNow($ok->end_at->addDay()->setTime(10, 0));
        Mail::fake();
        $this->reviews->inviteDue();

        $this->actingAs($this->admin);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->get('/admin/review-invitations')->assertOk()->assertSee('Invitations Aux Avis')->assertSee($ok->reference)->assertSee($noEmail->reference);

        $before = collect(['bookings', 'reviews', 'booking_events', 'booking_notifications', 'activity_logs'])->mapWithKeys(fn ($t) => [$t => DB::table($t)->count()])->all();

        Livewire::test(ListReviewInvitations::class)
            ->assertCanSeeTableRecords([$ok, $noEmail])
            ->filterTable('invitation_status', ['not_applicable'])->assertCanSeeTableRecords([$noEmail])->assertCanNotSeeTableRecords([$ok])
            ->resetTableFilters()
            ->filterTable('has_email', false)->assertCanSeeTableRecords([$noEmail])->assertCanNotSeeTableRecords([$ok])
            ->resetTableFilters()
            ->filterTable('spa_id', $this->spa->id)->assertCanSeeTableRecords([$ok, $noEmail])
            ->resetTableFilters()
            ->filterTable('end_at', ['from' => $ok->end_at->addDays(3)->toDateString(), 'until' => null])->assertCanNotSeeTableRecords([$ok, $noEmail])
            ->resetTableFilters()
            ->filterTable('invited_at', ['from' => now()->toDateString(), 'until' => now()->toDateString()])->assertCanSeeTableRecords([$ok])->assertCanNotSeeTableRecords([$noEmail])
            ->resetTableFilters()
            ->set('activeTab', 'sent')->assertCanSeeTableRecords([$ok])->assertCanNotSeeTableRecords([$noEmail])
            ->set('activeTab', 'not_applicable')->assertCanSeeTableRecords([$noEmail])->assertCanNotSeeTableRecords([$ok])
            ->assertTableActionDoesNotExist('edit')->assertTableActionDoesNotExist('delete');

        $after = collect(array_keys($before))->mapWithKeys(fn ($t) => [$t => DB::table($t)->count()])->all();
        $this->assertSame($before, $after, 'La consultation n’écrit rien');
        $this->assertFalse(ReviewInvitationResource::canCreate());
        $this->assertFalse(ReviewInvitationResource::canEdit($ok));
        $this->assertFalse(ReviewInvitationResource::canDelete($ok));
    }

    public function test_partner_and_anonymous_cannot_access(): void
    {
        $this->get('/admin/review-invitations')->assertRedirect('/admin/login');
        $this->actingAs($this->spa->partner->user)->get('/admin/review-invitations')->assertForbidden();
    }

    public function test_admin_reviews_moderation_list_still_hides_invited(): void
    {
        $b = $this->completed('10:00');
        CarbonImmutable::setTestNow($b->end_at->addDay()->setTime(10, 0));
        Mail::fake();
        $this->reviews->inviteDue();
        $this->actingAs($this->admin);
        $this->get('/admin/reviews')->assertOk()->assertDontSee($b->reference);
    }
}
