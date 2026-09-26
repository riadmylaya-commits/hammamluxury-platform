<x-mail::message>
# {{ __('emails.'.$event.'.'.$audience.'.title', ['ref' => $booking->reference]) }}

{{ __('emails.'.$event.'.'.$audience.'.intro', ['spa' => $booking->spa->name, 'name' => $booking->customerName()]) }}

**{{ __('booking.reference') }} :** {{ $booking->reference }}
**{{ __('booking.spa') }} :** {{ $booking->spa->name }}
**{{ __('booking.date') }} :** {{ $booking->start_at->translatedFormat('l j F Y') }} · {{ $booking->start_at->format('H:i') }} → {{ $booking->end_at->format('H:i') }}
**{{ __('booking.people') }} :** {{ $booking->party }}
**{{ __('booking.total') }} :** {{ number_format($booking->total, 0, ',', ' ') }} {{ $booking->currency }}

@foreach($booking->quote['lines'] as $line)
- {{ $line['party'] }} × {{ $line['treatment_name'] }}@if($line['extras']) (+ {{ implode(', ', array_map(fn($e) => $e['name'], $line['extras'])) }})@endif
@endforeach

@if($audience === 'client' && in_array($event, ['created', 'confirmed']))
<x-mail::button :url="route('booking.show', ['locale' => $booking->locale, 'token' => $booking->manage_token])">
{{ __('booking.follow') }}
</x-mail::button>
@endif
@if($audience === 'partner' && $event === 'created')
{{ __('emails.created.partner.deadline', ['hours' => config('hl.waiting_ttl_hours')]) }}
@endif

{{ config('app.name') }}
</x-mail::message>
