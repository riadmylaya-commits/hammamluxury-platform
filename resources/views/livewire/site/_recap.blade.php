<h3>{{ __('ui.recap') }}</h3>
<dl>
    <dt>{{ __('ui.recap_spa') }}</dt><dd>{{ $spa->name }}</dd>
    @foreach ($quote['lines'] as $l)
        <dt>{{ count($quote['lines']) > 1 ? __('ui.person_n', ['n' => $l['participant_no']]) : __('ui.recap_treatment') }}</dt>
        <dd>{{ $l['treatment_name'] }}@if ($l['party'] > 1) ×{{ $l['party'] }}@endif<br><span class="xs muted">{{ __('ui.formula.'.$l['formula']) }} · {{ $fmt($l['base_price']) }}</span>
            @foreach ($l['extras'] as $e)<br><span class="xs muted">+ {{ $e['name'] }}@if ($e['qty'] > 1) ×{{ $e['qty'] }}@endif · {{ $fmt($e['price']) }}</span>@endforeach</dd>
    @endforeach
    @if ($date)<dt>{{ __('ui.recap_date') }}</dt><dd>{{ \Carbon\CarbonImmutable::parse($date)->locale(app()->getLocale())->isoFormat('ddd D MMM YYYY') }}</dd>@endif
    @if ($time)<dt>{{ __('ui.recap_time') }}</dt><dd>{{ $time }} → {{ \Carbon\CarbonImmutable::parse("$date $time")->addMinutes($quote['duration_min'])->format('H:i') }}</dd>@endif
    <dt>{{ __('ui.recap_party') }}</dt><dd>{{ $quote['party'] }}</dd>
    <dt>{{ __('ui.recap_duration') }}</dt><dd>{{ $quote['duration_min'] }} {{ __('ui.min') }}</dd>
</dl>
<div class="tot"><span>{{ __('ui.recap_total') }}</span><span>{{ $fmt($quote['total']) }}</span></div>
<div class="xs muted" style="text-align:right">{{ __('ui.pay_on_site') }}</div>
