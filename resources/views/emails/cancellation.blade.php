<x-mail::message>
# {{ __("emails.cancellation.$event.$audience.title", ['ref' => $booking->reference, 'spa' => $booking->spa->name]) }}

{{ __("emails.cancellation.$event.$audience.intro", ['spa' => $booking->spa->name, 'name' => $booking->customerName(), 'response' => __('emails.cancellation.response.'.($request->client_response ?? 'none'))]) }}

**{{ __('booking.reference') }} :** {{ $booking->reference }}
**{{ __('booking.spa') }} :** {{ $booking->spa->name }}
**{{ __('booking.date') }} :** {{ $booking->start_at->translatedFormat('l j F Y') }} · {{ $booking->start_at->format('H:i') }} → {{ $booking->end_at->format('H:i') }}

@if($event === 'requested')
**{{ __('emails.cancellation.reason') }} :** {{ $request->reason }}
@endif
@if($event === 'refused' && $request->decision_note)
**{{ __('emails.cancellation.decision_note') }} :** {{ $request->decision_note }}
@endif

<x-mail::button :url="$url">
{{ __("emails.cancellation.button.$audience") }}
</x-mail::button>

{{ config('app.name') }}
</x-mail::message>
