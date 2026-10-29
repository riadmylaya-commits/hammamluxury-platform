<x-mail::message>
# {{ __('emails.partner_invite.title', ['spa' => $spa->name]) }}

{{ __('emails.partner_invite.intro', ['name' => $user->first_name ?: $user->name, 'spa' => $spa->name]) }}

<x-mail::button :url="$url">
{{ __('emails.partner_invite.button') }}
</x-mail::button>

{{ __('emails.partner_invite.expires', ['hours' => $hours, 'url' => url('/partenaire/password-reset/request')]) }}

{{ __('emails.partner_invite.scope') }}

{{ config('app.name') }}
</x-mail::message>
