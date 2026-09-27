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
            @if ($b->spa->phone)<div class="small"><a href="tel:{{ $b->spa->phone }}">{{ \App\Domain\Phone\PhoneNumber::format($b->spa->phone) }}</a>@if ($wa = \App\Domain\Phone\PhoneNumber::whatsappUrl($b->spa->phone)) · <a href="{{ $wa }}" target="_blank" rel="noopener">WhatsApp</a>@endif</div>@endif
        @else
            <div class="muted xs" style="margin-top:6px">{{ __('ui.contact_after') }}</div>
        @endif
    </div>

    @php($cr = $b->cancellationRequests->first())
    @if ($cr && ($cr->isPending() || $cr->decided_at?->gt(now()->subDays(30))))
        <div class="card" style="padding:16px;margin-top:14px;border-left:4px solid {{ $cr->isPending() ? '#d98e04' : ($cr->status === 'accepted' ? '#b42318' : '#1a7f4b') }}" id="cancellation">
            <b class="small">{{ __('ui.cancel_request_title') }}</b>
            <p class="small" style="margin:6px 0">{{ __('ui.cancel_request_intro', ['spa' => $b->spa->name, 'date' => $cr->created_at->locale(app()->getLocale())->isoFormat('LLL')]) }}</p>
            <blockquote class="small" style="margin:6px 0;padding:8px 12px;background:#f6f1ea;border-radius:8px">{{ $cr->reason }}</blockquote>
            @if ($cr->isPending())
                @if ($cr->client_response)
                    <p class="small"><b>{{ __('ui.cancel_request_your_answer') }}</b> {{ __('ui.cancel_request_answers.'.$cr->client_response) }} · {{ __('ui.cancel_request_waiting_admin') }}</p>
                @else
                    <p class="small">{{ __('ui.cancel_request_ask') }}</p>
                    <div class="row" style="gap:8px;flex-wrap:wrap">
                        <button class="btn sm" type="button" wire:click="respondCancellation('accepted')" wire:loading.attr="disabled">{{ __('ui.cancel_request_accept') }}</button>
                        <button class="btn sec sm" type="button" wire:click="respondCancellation('refused')" wire:loading.attr="disabled">{{ __('ui.cancel_request_refuse') }}</button>
                    </div>
                    <p class="xs muted" style="margin-top:6px">{{ __('ui.cancel_request_note') }}</p>
                @endif
            @else
                <p class="small"><b>{{ __('ui.cancel_request_decision.'.$cr->status) }}</b></p>
            @endif
        </div>
    @endif

    @if ($b->isActive() || $b->status === 'completed' || $b->messages->isNotEmpty())
        <div class="card" style="padding:16px;margin-top:14px" id="messages">
            <h3 style="font-size:17px">{{ __('ui.messages_title') }}</h3>
            <p class="muted xs" style="margin:4px 0 10px">{{ __('ui.messages_help', ['spa' => $b->spa->name]) }}</p>
            <div class="hl-thread">
                @forelse ($b->messages as $m)
                    <div class="hl-msg {{ $m->sender === 'client' ? 'hl-msg-me' : '' }}" wire:key="msg-{{ $m->id }}">
                        <div class="xs muted">{{ $m->sender === 'client' ? __('ui.you') : $b->spa->name }} · {{ $m->created_at->locale(app()->getLocale())->isoFormat('lll') }}</div>
                        <p style="margin:2px 0 0;white-space:pre-line">{{ $m->body }}</p>
                    </div>
                @empty
                    <p class="small muted">{{ __('ui.no_messages') }}</p>
                @endforelse
            </div>
            @if ($b->isActive() || $b->status === 'completed')
                <form wire:submit="sendMessage" style="margin-top:10px">
                    <textarea wire:model="messageBody" rows="3" maxlength="2000" required placeholder="{{ __('ui.message_placeholder') }}" style="width:100%;padding:10px;border:1px solid #ddd;border-radius:10px;font:inherit"></textarea>
                    <div class="row" style="justify-content:flex-end;margin-top:6px"><button class="btn sm" type="submit" wire:loading.attr="disabled">{{ __('ui.send_message') }}</button></div>
                </form>
            @endif
        </div>
        <style>.hl-thread{display:flex;flex-direction:column;gap:8px;max-height:360px;overflow:auto}.hl-msg{background:#f6f1ea;border-radius:12px;padding:8px 12px;max-width:85%;font-size:14px}.hl-msg-me{align-self:flex-end;background:#e8f3ec}</style>
    @endif

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
