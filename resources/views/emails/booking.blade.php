<x-mail::message>
# {{ __('emails.'.$event.'.'.$textAudience.'.title', ['ref' => $booking->reference, 'spa' => $booking->spa->name]) }}

{{ __('emails.'.$event.'.'.$textAudience.'.intro', ['spa' => $booking->spa->name, 'name' => $booking->customerName(), 'phone' => $booking->spa->phone, 'whatsapp' => $booking->spa->whatsapp ?: $booking->spa->phone]) }}

**{{ __('booking.reference') }} :** {{ $booking->reference }}
**{{ __('booking.spa') }} :** {{ $booking->spa->name }}
**{{ __('booking.date') }} :** {{ $booking->start_at->translatedFormat('l j F Y') }} · {{ $booking->start_at->format('H:i') }} → {{ $booking->end_at->format('H:i') }}
**{{ __('booking.people') }} :** {{ $booking->party }}
**{{ __('booking.total') }} :** {{ number_format($booking->total, 0, ',', ' ') }} {{ $booking->currency }}
**{{ __('booking.conditions') }} :** @if($booking->isNonRefundable()){{ __('ui.policy.nr_booking', ['pct' => $booking->nr_discount_pct, 'amount' => number_format($booking->total, 0, ',', ' ').' '.$booking->currency]) }}@if($booking->status === 'waiting') {{ __('ui.policy.pending_free') }}@endif @elseif($booking->cancellationToConfirm()){{ __('ui.policy.standard_desc_to_confirm') }}@else{{ __('ui.policy.standard_desc', ['label' => \App\Domain\Policy\CancellationPolicy::label($booking->cancellationHours())]) }}@if($booking->isActive()) {{ \App\Domain\Policy\CancellationPolicy::deadlineNotice($booking->freeCancellationUntil(), $booking->freeCancellationUntil()->translatedFormat('l j F Y H\hi'), number_format($booking->total, 0, ',', ' ').' '.$booking->currency, null, $booking->status === 'waiting') }}@endif @endif

@foreach($booking->quote['lines'] as $line)
- {{ $line['party'] }} × {{ $line['treatment_name'] }}@if($line['extras']) (+ {{ implode(', ', array_map(fn($e) => $e['name'], $line['extras'])) }})@endif
@endforeach

@if($audience === 'client' && in_array($event, ['created', 'confirmed']))
<x-mail::button :url="route('booking.show', ['locale' => $booking->locale, 'token' => $booking->manage_token])">
{{ __('booking.follow') }}
</x-mail::button>
@endif
@if($audience === 'client' && $event === 'created' && $booking->status === 'waiting' && $booking->expires_at)
@if($booking->last_minute)
{{ __('emails.created.client_last_minute', ['minutes' => config('hl.last_minute.response_minutes')]) }}

@endif
{{ __('emails.created.client_deadline', ['until' => $booking->expires_at->locale($booking->locale)->translatedFormat(__('emails.deadline_format'))]) }}
@endif
@if($audience === 'client' && $event === 'expired')
<x-mail::button :url="route('search', ['locale' => $booking->locale])">
{{ __('emails.expired.search_other') }}
</x-mail::button>
@endif
@if($audience === 'partner' && $event === 'created')
@if($booking->last_minute)
**{{ __('emails.created.partner_last_minute', ['minutes' => config('hl.last_minute.response_minutes')]) }}**

@endif
@if($booking->last_minute)
{{ __('emails.created.partner_last_minute_deadline', ['minutes' => config('hl.last_minute.response_minutes'), 'open' => config('hl.admin_hours')[0], 'close' => config('hl.admin_hours')[1], 'until' => $booking->expires_at?->translatedFormat('l j F Y H\hi')]) }}
@else
{{ __('emails.created.'.$textAudience.'.deadline', ['hours' => $booking->spa->waitingTtlHours(), 'open' => config('hl.admin_hours')[0], 'close' => config('hl.admin_hours')[1], 'until' => $booking->expires_at?->translatedFormat('l j F Y H\hi')]) }}
@endif
@endif

{{ config('app.name') }}
</x-mail::message>
