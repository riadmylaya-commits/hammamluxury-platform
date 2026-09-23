<div>
<div class="gallery" style="margin-top:6px">
    @forelse (array_slice($card['photos'], 0, 5) as $i => $p)
        <div class="ph {{ ['', 'green', 'sand', 'blue', ''][$i] }}"><img src="{{ $p['url'] }}" alt="{{ $p['caption'] ?? $spa->name }}" @if ($i) loading="lazy" @endif></div>
    @empty
        @foreach (['', 'green', 'sand', 'blue', ''] as $c)<div class="ph {{ $c }}"><span>{{ $spa->name }}</span></div>@endforeach
    @endforelse
</div>
<div class="spa-h">
    <div class="sp">
        <h1>{{ $spa->name }}</h1>
        <div class="muted">{{ $card['area'] ? $card['area'].' · ' : '' }}{{ $card['city'] }} · {{ __('ui.spa_cat.'.$card['category']) }}</div>
        <div class="row wrapg" style="margin-top:8px;gap:6px">
            @if ($card['rating'])<span class="rating"><b>{{ number_format($card['rating'], 1) }}</b><span class="small muted">{{ __('ui.reviews', ['count' => $card['reviews_count']]) }}</span></span>@else<span class="chip grey">{{ __('ui.no_reviews') }}</span>@endif
            @foreach ($card['features'] as $f)<span class="chip">{{ __('ui.features.'.$f) }}</span>@endforeach
        </div>
    </div>
</div>
<div class="spa-layout">
    <div>
        <section class="sec" style="padding-top:0" id="treatments">
            <h2>{{ __('ui.treatments') }}</h2>
            <div class="card">
                @foreach ($treatments as $t)
                    <div class="treat">
                        <div class="sp">
                            <h4>{{ $t['name'] }} @if ($t['is_package'])<span class="chip acc">{{ __('ui.package') }}</span>@endif</h4>
                            <div class="muted small">{{ __('ui.cat.'.$t['category']) }} · {{ $t['duration_min'] }} {{ __('ui.min') }}
                                @if ($t['is_package']) · {{ collect($t['steps'])->map(fn ($s) => $s['type'].' '.$s['duration_min'].' '.__('ui.min'))->implode(' → ') }}@endif</div>
                            @if ($t['description'])<p class="small" style="margin:6px 0 0">{{ $t['description'] }}</p>@endif
                            <div class="row wrapg" style="gap:6px;margin-top:6px">
                                @if ($t['price_couple'])<span class="chip grey">{{ __('ui.formula.couple') }} {{ number_format($t['price_couple'], 0, ',', ' ') }} {{ config('hl.currency') }}</span>@endif
                                @if ($t['price_group'])<span class="chip grey">{{ __('ui.formula.group') }} {{ number_format($t['price_group'], 0, ',', ' ') }} {{ config('hl.currency') }}{{ __('ui.per_person') }}</span>@endif
                                @foreach ($t['extras'] as $e)<span class="chip grey">+ {{ $e['name'] }} · +{{ number_format($e['price'], 0, ',', ' ') }} {{ config('hl.currency') }}@if ($e['extra_min']) / +{{ $e['extra_min'] }} {{ __('ui.min') }}@endif</span>@endforeach
                            </div>
                        </div>
                        <div class="r"><b>{{ number_format($t['price_from'], 0, ',', ' ') }} {{ config('hl.currency') }}</b><span class="xs muted">{{ __('ui.per_person') }}</span><br>
                            <a class="btn sm" style="margin-top:8px" href="{{ route('spa.book', [$spa->slug, 'soin' => $t['id']]) }}">{{ __('ui.book') }}</a></div>
                    </div>
                @endforeach
            </div>
        </section>
        @if ($card['description'])
        <section class="sec"><h2>{{ __('ui.about') }}</h2><p style="white-space:pre-line">{{ $card['description'] }}</p></section>
        @endif
        <section class="sec"><h2>{{ __('ui.hours') }}</h2>
            <div class="card hours" style="padding:14px">
                @foreach (__('ui.days') as $i => $d)
                    <span @class(['b' => $i === now()->dayOfWeekIso - 1])>{{ $d }}</span><span @class(['muted' => empty($card['hours'][$i])])>{{ empty($card['hours'][$i]) ? __('ui.closed') : implode(', ', $card['hours'][$i]) }}</span>
                @endforeach
            </div>
            <p class="muted small" style="margin-top:10px">{{ __('ui.contact_after') }} {{ __('ui.cancel_policy', ['hours' => $spa->cancellation_hours]) }}</p>
        </section>
    </div>
    <aside class="side card">
        <div class="xs muted">{{ __('ui.from') }}</div>
        <div style="font-size:26px;font-weight:700">{{ $card['price_from'] ? number_format($card['price_from'], 0, ',', ' ').' '.config('hl.currency') : '—' }} <span class="small muted">{{ __('ui.per_person') }}</span></div>
        <a class="btn full" style="margin-top:12px" href="{{ route('spa.book', $spa->slug) }}">{{ __('ui.book') }}</a>
        <div class="divider"></div>
        <div class="small muted">{{ __('ui.no_account') }}</div>
    </aside>
</div>
<div class="mbar">
    <div class="sp"><span class="xs muted">{{ __('ui.from') }}</span><br><b>{{ $card['price_from'] ? number_format($card['price_from'], 0, ',', ' ').' '.config('hl.currency') : '—' }}</b></div>
    <a class="btn" href="{{ route('spa.book', $spa->slug) }}">{{ __('ui.book') }}</a>
</div>
</div>
