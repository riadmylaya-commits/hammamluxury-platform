<div>
@php($b = $booking)
@php($cur = config('hl.currency'))
@php($fmt = fn ($n) => number_format($n, 0, ',', ' ').' '.$cur)
@php($tone = ['waiting' => 'warn', 'confirmed' => 'ok', 'completed' => 'ok', 'declined' => 'bad', 'cancelled' => 'grey', 'expired' => 'grey', 'no_show' => 'grey'][$b->status])
@if ($new && $b->isWaiting())
    <div class="check">✓</div>
    <h1 style="text-align:center;font-size:26px">{{ __('ui.done_title') }}</h1>
    <p class="muted" style="text-align:center">{{ __('ui.done_sub', ['email' => $b->email]) }}</p>
@else
    <h1 style="font-size:24px;margin-top:14px">{{ __('ui.booking_ref', ['ref' => $b->reference]) }}</h1>
@endif
@if ($flash)<div class="notice ok" style="margin:10px 0">{{ $flash }}</div>@endif
@if ($error)<div class="notice bad" style="margin:10px 0">{{ $error }}</div>@endif

<div class="book-layout" style="margin-top:14px">
<div>
    <div class="card" style="padding:16px">
        <div class="row"><span class="chip {{ $tone }}">{{ __('ui.status.'.$b->status) }}</span><span class="sp"></span><span class="muted small">{{ $b->reference }}</span></div>
        <p class="small" style="margin:10px 0 0">{{ __('ui.status_hint.'.$b->status, ['deadline' => $b->expires_at?->locale(app()->getLocale())->isoFormat('LLL')]) }}</p>
        @if ($b->isWaiting() || $b->isConfirmed())
        <ul class="tl">
            <li data-on><i></i>{{ __('ui.timeline.sent') }} · {{ $b->created_at->locale(app()->getLocale())->isoFormat('LLL') }}</li>
            <li @if ($b->isConfirmed()) data-on @endif><i></i>{{ __('ui.timeline.confirm') }}@if ($b->confirmed_at) · {{ $b->confirmed_at->locale(app()->getLocale())->isoFormat('LLL') }}@endif</li>
            <li><i></i>{{ __('ui.timeline.visit') }} · {{ $b->start_at->locale(app()->getLocale())->isoFormat('LLLL') }}</li>
        </ul>
        @endif
    </div>

    <div class="card" style="padding:16px;margin-top:14px">
        <h3 style="font-size:17px">{{ $b->spa->name }}</h3>
        <div class="muted small">{{ $b->spa->area ? $b->spa->area.' · ' : '' }}{{ $b->spa->city }}</div>
        @if ($b->isConfirmed() || $b->status === 'completed')
            <div class="divider"></div>
            <b class="small">{{ __('ui.spa_contact') }}</b>
            <div class="small">{{ $b->spa->address }}</div>
            @if ($b->spa->phone)<div class="small"><a href="tel:{{ $b->spa->phone }}">{{ $b->spa->phone }}</a></div>@endif
        @else
            <div class="muted xs" style="margin-top:6px">{{ __('ui.contact_after') }}</div>
        @endif
    </div>

    @if ($b->isActive())
        <div style="margin-top:14px" x-data="{ ask: false }">
            <button class="btn bad sm" type="button" x-show="!ask" x-on:click="ask = true">{{ __('ui.cancel_booking') }}</button>
            <div class="row" x-show="ask" x-cloak><span class="small">{{ __('ui.cancel_confirm') }}</span><button class="btn bad sm" type="button" wire:click="cancel">{{ __('ui.cancel_booking') }}</button><button class="btn ghost sm" type="button" x-on:click="ask = false">{{ __('ui.back') }}</button></div>
        </div>
    @else
        <a class="btn sec sm" style="margin-top:14px" href="{{ route('spa.show', $b->spa->slug) }}">{{ __('ui.book_again') }}</a>
    @endif
</div>
<aside class="card recap">
    <h3>{{ __('ui.recap') }}</h3>
    <dl>
        @foreach ($b->quote['lines'] as $l)
            <dt>{{ count($b->quote['lines']) > 1 ? __('ui.person_n', ['n' => $l['participant_no']]) : __('ui.recap_treatment') }}</dt>
            <dd>{{ $l['treatment_name'] }}@if ($l['party'] > 1) ×{{ $l['party'] }}@endif @foreach ($l['extras'] as $e)<br><span class="xs muted">+ {{ $e['name'] }}@if ($e['qty'] > 1) ×{{ $e['qty'] }}@endif</span>@endforeach</dd>
        @endforeach
        <dt>{{ __('ui.recap_date') }}</dt><dd>{{ $b->start_at->locale(app()->getLocale())->isoFormat('ddd D MMM YYYY') }}</dd>
        <dt>{{ __('ui.recap_time') }}</dt><dd>{{ $b->start_at->format('H:i') }} → {{ $b->end_at->format('H:i') }}</dd>
        <dt>{{ __('ui.recap_party') }}</dt><dd>{{ $b->party }}</dd>
        <dt>{{ __('ui.recap_duration') }}</dt><dd>{{ $b->duration_min }} {{ __('ui.min') }}</dd>
        <dt>{{ __('ui.first_name') }}</dt><dd>{{ $b->first_name }} {{ $b->last_name }}</dd>
        @if ($b->hotel)<dt>{{ __('ui.hotel') }}</dt><dd>{{ $b->hotel }}</dd>@endif
    </dl>
    <div class="tot"><span>{{ __('ui.recap_total') }}</span><span>{{ $fmt($b->total) }}</span></div>
    <div class="xs muted" style="text-align:right">{{ __('ui.pay_on_site') }}</div>
</aside>
</div>
</div>
