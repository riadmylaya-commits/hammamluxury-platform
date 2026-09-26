<x-mail::message>
# {{ __('emails.spa.'.$event.'.title', ['spa' => $spa->name]) }}

{{ __('emails.spa.'.$event.'.intro', ['spa' => $spa->name, 'partner' => $spa->partner?->company_name, 'city' => $spa->city]) }}

@if($event === 'refused' && $spa->status_note)
**{{ __('emails.spa.reason') }} :** {{ $spa->status_note }}
@endif

<x-mail::button :url="$url">
{{ __('emails.spa.'.$event.'.button') }}
</x-mail::button>

{{ config('app.name') }}
</x-mail::message>
