<a class="res card" href="{{ route('spa.show', $s['slug']) }}">
    <div class="ph {{ ['', 'green', 'sand', 'blue'][$s['id'] % 4] }}">@if ($s['photos'])<img src="{{ $s['photos'][0]['url'] }}" alt="{{ $s['photos'][0]['caption'] ?? $s['name'] }}" loading="lazy">@endif</div>
    <div class="body">
        <div class="row" style="align-items:flex-start">
            <div class="sp">
                <h3>{{ $s['name'] }}</h3>
                <div class="muted small">{{ $s['area'] ? $s['area'].' · ' : '' }}{{ $s['city'] }} · {{ $s['category_label'] }}</div>
                <div class="row" style="margin-top:6px">
                    @if ($s['rating'])<span class="rating"><b>{{ number_format($s['rating'], 1) }}</b><span class="small muted">{{ __('ui.reviews', ['count' => $s['reviews_count']]) }}</span></span>@else<span class="chip grey">{{ __('ui.no_reviews') }}</span>@endif
                </div>
                <div class="row wrapg" style="margin-top:8px;gap:6px">
                    @foreach (array_slice($s['features'], 0, 3) as $f)<span class="chip">{{ $f }}</span>@endforeach
                    <span class="chip grey">{{ trans_choice('ui.treatments_count', $s['treatments_count'] ?? 0) }}</span>
                </div>
            </div>
            @if ($s['price_from'])<div class="price"><span class="xs muted">{{ __('ui.from') }}</span><br><b>{{ number_format($s['price_from'], 0, ',', ' ') }} {{ config('hl.currency') }}</b><br><span class="xs muted">{{ __('ui.per_person') }}</span></div>@endif
        </div>
    </div>
</a>
