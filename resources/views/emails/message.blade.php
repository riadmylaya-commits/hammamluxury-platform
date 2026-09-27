<x-mail::message>
# {{ __("emails.message.$audience.title", ['ref' => $booking->reference, 'spa' => $booking->spa->name]) }}

{{ __("emails.message.$audience.intro", ['spa' => $booking->spa->name, 'name' => $booking->customerName()]) }}

> {{ \Illuminate\Support\Str::limit($message->body, 300) }}

**{{ __('booking.reference') }} :** {{ $booking->reference }}
**{{ __('booking.date') }} :** {{ $booking->start_at->translatedFormat('l j F Y') }} · {{ $booking->start_at->format('H:i') }}

<x-mail::button :url="$url">
{{ __('emails.message.button') }}
</x-mail::button>

{{ __('emails.message.footer') }}

{{ config('app.name') }}
</x-mail::message>
