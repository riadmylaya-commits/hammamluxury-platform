<x-mail::message>
# {{ __('emails.guest_report.title') }}

{{ __('emails.guest_report.intro', ['spa' => $booking->spa->name, 'ref' => $booking->reference]) }}

**{{ __('booking.reference') }} :** {{ $booking->reference }}
**{{ __('booking.spa') }} :** {{ $booking->spa->name }}
**{{ __('booking.date') }} :** {{ $booking->start_at->translatedFormat('l j F Y') }} · {{ $booking->start_at->format('H:i') }}
**{{ __('emails.guest_report.category') }} :** {{ __('admin.incident_categories.'.$incident->category) }}

> {{ $incident->description }}

<x-mail::button :url="$url">
{{ __('emails.guest_report.button') }}
</x-mail::button>

{{ config('app.name') }}
</x-mail::message>
