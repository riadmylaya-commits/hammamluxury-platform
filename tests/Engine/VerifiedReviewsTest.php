<?php

namespace Tests\Engine;

use App\Domain\Booking\BookingException;
use App\Domain\Review\ReviewService;
use App\Filament\Admin\Resources\ReviewResource\Pages\ListReviews as AdminListReviews;
use App\Filament\Admin\Resources\ReviewResource\Pages\ViewReview;
use App\Filament\Partner\Resources\ReviewResource\Pages\ListReviews as PartnerListReviews;
use App\Livewire\Site\BookingShow;
use App\Livewire\Site\ReviewForm;
use App\Livewire\Site\SpaShow;
use App\Mail\ReviewInviteMail;
use App\Models\Booking;
use App\Models\Review;
use App\Models\User;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;

/**
 * Session 2 — avis vérifiés : invitation J+1 des prestations effectuées (jamais no-show), un avis par réservation,
 * parcours en deux étapes (note + critères, texte ≥ 20 car. + photos facultatives privées), modération motivée, réponse partenaire modérée.
 */
class VerifiedReviewsTest extends BookingFlowTestCase
{
    private User $admin;

    private ReviewService $reviews;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('fr');
        URL::defaults(['locale' => 'fr']);
        Storage::fake('local');
        Storage::fake('public');
        $this->reviews = app(ReviewService::class);
        $this->admin = User::create(['name' => 'Admin', 'email' => 'admin@example.test', 'password' => 'secret-test', 'role' => 'admin', 'email_verified_at' => now()]);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function completed(string $time = '10:00'): Booking
    {
        CarbonImmutable::setTestNow();
        $b = $this->submit($this->intent($time, ['treatment' => $this->m->id, 'party' => 1]))['booking'];
        Mail::fake();
        $this->bookings->accept($b, 'partner');
        CarbonImmutable::setTestNow($b->end_at->addHours(5));
        $this->bookings->completePast();

        return $b->fresh();
    }

    private function asPartner(): void
    {
        $this->actingAs($this->spa->partner->user);
        Filament::setCurrentPanel(Filament::getPanel('partner'));
        Filament::setTenant($this->spa, true);
    }

    private function asAdmin(): void
    {
        $this->actingAs($this->admin);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Filament::setTenant(null, true);
    }

    private function submitted(array $over = [], array $photos = []): Review
    {
        $b = $this->completed();
        $r = $this->reviews->invite($b);

        return $this->reviews->submit($r, $over + ['rating' => 2, 'body' => 'Accueil froid et soin écourté de vingt minutes.', 'title' => 'Décevant'], $photos);
    }

    public function test_only_completed_bookings_are_invited_the_day_after_and_only_once(): void
    {
        $done = $this->completed('10:00');
        CarbonImmutable::setTestNow();
        $noShow = $this->submit($this->intent('12:00', ['treatment' => $this->m->id, 'party' => 1]))['booking'];
        $this->bookings->accept($noShow, 'partner');
        CarbonImmutable::setTestNow($noShow->start_at->addMinutes(10));
        $this->bookings->noShow($noShow->fresh(), 'partner');
        $this->assertFalse($this->reviews->isEligible($noShow->fresh()), 'Un no-show n’est jamais invité');

        Mail::fake();
        CarbonImmutable::setTestNow($done->end_at->addHours(6));
        $this->assertSame([], $this->reviews->inviteDue(), 'Le jour même : pas encore d’invitation');

        CarbonImmutable::setTestNow($done->end_at->addDay()->setTime(10, 0));
        $ids = $this->reviews->inviteDue();
        $this->assertCount(1, $ids);
        $review = Review::find($ids[0]);
        $this->assertSame($done->id, $review->booking_id);
        $this->assertSame('invited', $review->status);
        $this->assertNotNull($review->token);
        Mail::assertQueued(ReviewInviteMail::class, fn ($m) => $m->hasTo($done->email) && $m->review->is($review));

        $this->assertSame([], $this->reviews->inviteDue(), 'Relance idempotente');
        $this->assertSame(1, $done->events()->where('type', 'review:invited')->count());
        try {
            $this->reviews->invite($done->fresh());
            $this->fail('Un seul avis par réservation');
        } catch (BookingException $e) {
            $this->assertSame(__('booking.review_not_eligible'), $e->getMessage());
        }
    }

    public function test_submission_requires_rating_and_twenty_characters_and_keeps_photos_private(): void
    {
        $b = $this->completed();
        $r = $this->reviews->invite($b);

        foreach ([['rating' => 0, 'body' => str_repeat('a', 30)], ['rating' => 6, 'body' => str_repeat('a', 30)], ['rating' => 4, 'body' => 'Trop court.']] as $bad) {
            try {
                $this->reviews->submit($r->fresh(), $bad);
                $this->fail('Refusé : '.json_encode($bad));
            } catch (BookingException $e) {
                $this->assertSame('invited', $r->fresh()->status);
            }
        }

        $photos = [UploadedFile::fake()->image('a.jpg', 1200, 900), UploadedFile::fake()->image('b.png', 1000, 800)];
        $r = $this->reviews->submit($r->fresh(), [
            'rating' => 5, 'body' => 'Une parenthèse hors du temps, personnel adorable.', 'title' => 'Parfait', 'liked' => 'Le thé', 'improve' => '',
            'criteria' => ['welcome' => 3, 'cleanliness' => 3, 'bogus' => 3, 'value' => 9], 'bonus' => ['recommend' => 'yes', 'welcomed' => 'maybe'],
        ], $photos);
        $this->assertSame('pending', $r->status);
        $this->assertSame(['welcome' => 3, 'cleanliness' => 3], $r->criteria);
        $this->assertSame(['recommend' => 'yes'], $r->bonus);
        $this->assertNull($r->improve);
        $this->assertCount(2, $r->photos);
        foreach ($r->photos as $p) {
            $this->assertSame('local', $p->disk);
            Storage::disk('local')->assertExists($p->path);
            Storage::disk('public')->assertMissing($p->path);
            $this->assertNull($p->publicUrl());
        }
        $this->assertNull($this->spa->fresh()->rating, 'Pas de note publique avant modération');

        try {
            $this->reviews->submit($r->fresh(), ['rating' => 1, 'body' => str_repeat('b', 30)]);
            $this->fail('Second dépôt refusé');
        } catch (BookingException $e) {
            $this->assertSame(__('booking.review_already_submitted'), $e->getMessage());
        }

        try {
            $this->reviews->submit($this->reviews->invite($this->completed('17:00')), ['rating' => 4, 'body' => str_repeat('c', 30)], array_fill(0, 6, UploadedFile::fake()->image('x.jpg', 1000, 800)));
            $this->fail('Max 5 photos');
        } catch (BookingException $e) {
            $this->assertSame(__('booking.review_too_many_photos', ['max' => 5]), $e->getMessage());
        }
    }

    public function test_rejection_requires_content_reason_and_publication_exposes_photos_and_rating(): void
    {
        $r = $this->submitted([], [UploadedFile::fake()->image('p.jpg', 1000, 800)]);

        try {
            $this->reviews->moderate($r, $this->admin, 'rejected');
            $this->fail('Motif obligatoire');
        } catch (BookingException $e) {
            $this->assertSame(__('booking.review_rejection_reason_required'), $e->getMessage());
        }
        try {
            $this->reviews->moderate($r, $this->admin, 'rejected', 'negative');
            $this->fail('« Négatif » n’est pas un motif');
        } catch (BookingException $e) {
            $this->assertSame('pending', $r->fresh()->status);
        }
        try {
            $this->reviews->moderate($r, $this->admin, 'rejected', 'other', 'court');
            $this->fail('« Autre » exige une précision');
        } catch (BookingException $e) {
            $this->assertSame(__('booking.review_rejection_note_required'), $e->getMessage());
        }

        $r = $this->reviews->moderate($r, $this->admin, 'published');
        $this->assertSame('published', $r->status);
        $this->assertNotNull($r->published_at);
        $photo = $r->photos->first();
        $this->assertSame('public', $photo->disk);
        Storage::disk('public')->assertExists($photo->path);
        Storage::disk('local')->assertMissing($photo->path);
        $spa = $this->spa->fresh();
        $this->assertSame(2.0, (float) $spa->rating);
        $this->assertSame(1, (int) $spa->reviews_count);
        $this->assertSame('admin', $r->booking->events()->where('type', 'review:published')->value('actor'));

        $r = $this->reviews->moderate($r, $this->admin, 'rejected', 'personal_data');
        $this->assertSame('rejected', $r->status);
        $this->assertSame('personal_data', $r->rejection_reason);
        $this->assertSame('local', $r->photos->first()->disk, 'Photo redevenue privée');
        Storage::disk('public')->assertMissing($photo->path);
        $this->assertNull($this->spa->fresh()->rating);
        $this->assertSame(0, (int) $this->spa->fresh()->reviews_count);
    }

    public function test_partner_reply_is_moderated_before_public_display(): void
    {
        $r = $this->submitted();
        try {
            $this->reviews->reply($r, $this->spa->partner->user, 'Merci pour votre retour, nous en tenons compte.');
            $this->fail('Pas de réponse à un avis non publié');
        } catch (BookingException $e) {
            $this->assertSame(__('booking.review_reply_not_allowed'), $e->getMessage());
        }
        $r = $this->reviews->moderate($r, $this->admin, 'published');

        $this->asPartner();
        Livewire::test(PartnerListReviews::class)->assertCanSeeTableRecords([$r])
            ->callTableAction('reply', $r, ['reply' => 'Merci pour votre retour, nous avons renforcé notre équipe d’accueil.'])
            ->assertHasNoTableActionErrors();
        $r->refresh();
        $this->assertSame('pending', $r->reply_status);
        $this->assertFalse($r->replyPublished());
        $this->assertFalse($r->canReply());

        Livewire::test(SpaShow::class, ['spa' => $this->spa])->assertSee('Décevant')->assertSee('Avis vérifié')->assertDontSee('renforcé notre équipe');

        $this->asAdmin();
        try {
            $this->reviews->moderateReply($r, $this->admin, 'rejected');
            $this->fail('Motif obligatoire pour refuser une réponse');
        } catch (BookingException $e) {
            $this->assertSame('pending', $r->fresh()->reply_status);
        }
        Livewire::test(ViewReview::class, ['record' => $r->getRouteKey()])->callAction('publish_reply')->assertHasNoActionErrors();
        $this->assertTrue($r->fresh()->replyPublished());
        Livewire::test(SpaShow::class, ['spa' => $this->spa])->assertSee('renforcé notre équipe')->assertSee('Réponse de');
    }

    public function test_client_two_step_form_and_admin_list(): void
    {
        $b = $this->completed();
        $r = $this->reviews->invite($b);

        Livewire::test(BookingShow::class, ['token' => $b->manage_token])->assertSee('Donner mon avis')->assertSee(route('review.form', $r->token));

        $page = Livewire::test(ReviewForm::class, ['token' => $r->token])
            ->assertSet('step', 1)->assertSee('Accueil / personnel')
            ->call('next')->assertHasErrors('rating')->assertSet('step', 1)
            ->call('setRating', 2)->call('setCriterion', 'cleanliness', 1)->call('setBonus', 'recommend', 'no')
            ->call('next')->assertSet('step', 2)
            ->assertSee('ce qui n’a pas répondu à vos attentes')->assertSee('pas de nudité')
            ->set('body', 'Trop court')->call('submit')->assertHasErrors(['body'])
            ->set('body', 'Le hammam était tiède et la gommeuse pressée, dommage.')->set('title', 'Mitigé')
            ->call('submit')->assertHasNoErrors()->assertSet('done', true)->assertSee('Merci pour votre avis');
        $r->refresh();
        $this->assertSame('pending', $r->status);
        $this->assertSame(['cleanliness' => 1], $r->criteria);
        $this->assertSame(['recommend' => 'no'], $r->bonus);

        Livewire::test(ReviewForm::class, ['token' => $r->token])->assertSet('done', true);
        $this->get(route('review.form', 'inconnu'))->assertNotFound();

        $this->asAdmin();
        Livewire::test(AdminListReviews::class)->assertCanSeeTableRecords([$r])->assertSee('Mitigé');
        Livewire::test(ReviewForm::class, ['token' => $this->reviews->invite($this->completed('17:00'))->token])
            ->call('setRating', 5)->call('next')->assertDontSee('ce qui n’a pas répondu à vos attentes');
    }

    public function test_private_review_photo_route_is_restricted(): void
    {
        $r = $this->submitted([], [UploadedFile::fake()->image('p.jpg', 1000, 800)]);
        $photo = $r->photos->first();
        $this->get(route('review.photo', $photo))->assertForbidden();
        $other = User::create(['name' => 'Autre', 'email' => 'autre@example.test', 'password' => 'secret-test', 'role' => 'partner', 'email_verified_at' => now()]);
        $this->actingAs($other)->get(route('review.photo', $photo))->assertForbidden();
        $this->actingAs($this->spa->partner->user)->get(route('review.photo', $photo))->assertOk();
        $this->actingAs($this->admin)->get(route('review.photo', $photo))->assertOk();
    }
}
