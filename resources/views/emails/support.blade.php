<x-mail::message>
# {{ __('support.mail.'.$kind.'.title', ['ref' => $request->reference]) }}

{{ __('support.mail.'.$kind.'.intro', ['name' => $request->name]) }}

**{{ __('support.reference') }} :** {{ $request->reference }}
**{{ __('support.subject') }} :** {{ $request->subject }}
@if($kind === 'admin')
**{{ __('support.from') }} :** {{ $request->name }} · {{ $request->email }} · {{ __('support.sources.'.$request->source) }}
@if($request->spa)
**{{ __('support.spa') }} :** {{ $request->spa->name }}
@endif
@endif

<x-mail::panel>
{!! nl2br(e($body ?? $request->body)) !!}
</x-mail::panel>

@if($kind === 'ack')
{{ __('support.mail.ack.'.($request->source === 'partner' ? 'partner_hint' : 'client_hint')) }}
@endif
@if($kind === 'reply')
{{ __('support.mail.reply.outro') }}
@endif

{{ config('app.name') }}
</x-mail::message>
