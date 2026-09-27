<x-mail::message>
# Nouveau message via le formulaire Contact

**Nom :** {{ $name }}
**E-mail :** {{ $email }}
**Sujet :** {{ $topic }}
**Langue :** {{ strtoupper($lang) }} · **IP :** {{ $ip ?: '—' }}

<x-mail::panel>
{!! nl2br(e($body)) !!}
</x-mail::panel>

Répondez directement à cet e-mail pour écrire à l'expéditeur.

{{ config('app.name') }}
</x-mail::message>
