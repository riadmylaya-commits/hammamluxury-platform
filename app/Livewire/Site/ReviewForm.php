<?php

namespace App\Livewire\Site;

use App\Domain\Booking\BookingException;
use App\Domain\Review\ReviewService;
use App\Models\Review;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

/**
 * Dépôt d'un avis vérifié depuis le lien unique reçu par e-mail, en deux étapes :
 * 1. note globale + critères (smileys) ; 2. apprécié / à améliorer / titre / commentaire (≥ 20 car.) / photos facultatives.
 */
#[Layout('components.layouts.site')]
class ReviewForm extends Component
{
    use WithFileUploads;

    public Review $review;

    public int $step = 1;

    public int $rating = 0;

    /** @var array<string, int> */
    public array $criteria = [];

    /** @var array<string, string> */
    public array $bonus = [];

    public string $liked = '';

    public string $improve = '';

    public string $title = '';

    public string $body = '';

    /** @var list<TemporaryUploadedFile> */
    public array $photos = [];

    public string $error = '';

    public bool $done = false;

    public function mount(string $token): void
    {
        $this->review = Review::where('token', $token)->with(['spa', 'booking'])->firstOrFail();
        $this->done = ! $this->review->isInvited();
    }

    public function setRating(int $value): void
    {
        $this->rating = max(1, min(5, $value));
        $this->resetErrorBag('rating');
    }

    public function setCriterion(string $key, int $value): void
    {
        if (in_array($key, Review::CRITERIA, true)) {
            $this->criteria[$key] = max(1, min(3, $value));
        }
    }

    public function setBonus(string $key, string $value): void
    {
        if (in_array($key, Review::BONUS, true) && in_array($value, ['yes', 'no'], true)) {
            $this->bonus[$key] = ($this->bonus[$key] ?? null) === $value ? '' : $value;
            $this->bonus = array_filter($this->bonus);
        }
    }

    public function next(): void
    {
        if ($this->rating < 1) {
            $this->addError('rating', __('booking.review_rating_required'));

            return;
        }
        $this->step = 2;
    }

    public function back(): void
    {
        $this->step = 1;
    }

    public function removePhoto(int $index): void
    {
        unset($this->photos[$index]);
        $this->photos = array_values($this->photos);
        $this->clearPhotoErrors();
    }

    public function updatedPhotos(): void
    {
        $this->photos = array_slice(array_values($this->photos), 0, Review::MAX_PHOTOS);
        $this->clearPhotoErrors();
    }

    private function clearPhotoErrors(): void
    {
        foreach (array_keys($this->getErrorBag()->toArray()) as $key) {
            if (str_starts_with($key, 'photos')) {
                $this->resetErrorBag($key);
            }
        }
    }

    public function submit(ReviewService $reviews): void
    {
        $this->error = '';
        $this->validate([
            'body' => 'required|string|min:'.Review::MIN_BODY.'|max:3000',
            'title' => 'nullable|string|max:150',
            'liked' => 'nullable|string|max:1500',
            'improve' => 'nullable|string|max:1500',
            'photos' => 'array|max:'.Review::MAX_PHOTOS,
            'photos.*' => 'image|mimes:jpg,jpeg,png,webp|max:12288',
        ]);
        try {
            $reviews->submit($this->review, [
                'rating' => $this->rating, 'title' => $this->title, 'body' => $this->body,
                'liked' => $this->liked, 'improve' => $this->improve, 'criteria' => $this->criteria, 'bonus' => $this->bonus,
            ], $this->photos);
            $this->done = true;
        } catch (BookingException $e) {
            $this->error = $e->getMessage();
        }
    }

    public function render(): View
    {
        return view('livewire.site.review-form')->title(__('ui.review.page_title', ['spa' => $this->review->spa->name]));
    }
}
