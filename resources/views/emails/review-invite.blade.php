<x-mail::message>
# {{ __('emails.review_invite.title', ['spa' => $review->spa->name]) }}

{{ __('emails.review_invite.intro', ['name' => $booking->first_name, 'spa' => $review->spa->name, 'date' => $booking->start_at->translatedFormat('l j F Y')]) }}

<x-mail::button :url="$url">
{{ __('emails.review_invite.button') }}
</x-mail::button>

{{ __('emails.review_invite.footer') }}

{{ config('app.name') }}
</x-mail::message>
