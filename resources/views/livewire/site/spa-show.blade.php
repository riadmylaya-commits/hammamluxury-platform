<div>
@php($photos = $card['photos'])
<div class="gallery" style="margin-top:6px" @if ($photos) id="hl-gallery" data-photos='@json($photos)' data-i18n='@json($galleryI18n)' @endif>
    @forelse (array_slice($photos, 0, 5) as $i => $p)
        <button type="button" class="ph {{ ['', 'green', 'sand', 'blue', ''][$i] }}" data-index="{{ $i }}" aria-label="{{ __('ui.photo_of', ['n' => $i + 1, 'total' => count($photos)]) }}"><img src="{{ $p['url'] }}" alt="{{ $p['caption'] ?? $spa->name }}" @if ($i) loading="lazy" @endif></button>
    @empty
        @foreach (['', 'green', 'sand', 'blue', ''] as $c)<div class="ph {{ $c }}"><span>{{ $spa->name }}</span></div>@endforeach
    @endforelse
    @if ($photos)<button type="button" class="gall-all" data-index="0">{{ __('ui.all_photos', ['count' => count($photos)]) }}</button>@endif
</div>
<div class="spa-h">
    <div class="sp">
        <h1>{{ $spa->name }}</h1>
        <div class="muted">{{ $card['area'] ? $card['area'].' · ' : '' }}{{ $card['city'] }} · {{ $card['category_label'] }}</div>
        <div class="row wrapg" style="margin-top:8px;gap:6px">
            @if ($card['rating'])<span class="rating"><b>{{ number_format($card['rating'], 1) }}</b><span class="small muted">{{ __('ui.reviews', ['count' => $card['reviews_count']]) }}</span></span>@else<span class="chip grey">{{ __('ui.no_reviews') }}</span>@endif
            @foreach ($card['features'] as $f)<span class="chip">{{ $f }}</span>@endforeach
        </div>
    </div>
</div>
<div class="spa-layout">
    <div>
        @if ($card['description'])
        <section class="sec" style="padding-top:0" id="about"><h2>{{ __('ui.about') }}</h2><p style="white-space:pre-line">{{ $card['description'] }}</p></section>
        @endif
        <section class="sec" @if (! $card['description']) style="padding-top:0" @endif id="treatments">
            <h2>{{ __('ui.treatments') }}</h2>
            <div class="card">
                @php($cur = config('hl.currency'))
                @foreach ($treatments as $t)
                    <div @class(['treat', 'featured' => (bool) $t['badge']])>
                        <div class="sp">
                            @if ($t['badge'])<span class="chip badge">★ {{ $t['badge'] }}</span>@endif
                            <h4>{{ $t['name'] }} @if ($t['is_package'])<span class="chip acc">{{ __('ui.package') }}</span>@endif</h4>
                            @if ($t['is_package'])
                                <div class="pkg">
                                    @foreach ($t['steps'] as $s)<span class="pkg-step"><b>{{ $s['type'] }}</b> {{ $s['duration_min'] }} {{ __('ui.min') }}</span>@if (! $loop->last)<span class="pkg-plus">+</span>@endif @endforeach
                                </div>
                                <div class="small"><b>{{ __('ui.total_duration') }} {{ $t['duration_label'] }}</b> <span class="muted">· {{ __('ui.cat.'.$t['category']) }}</span></div>
                            @else
                                <div class="muted small">{{ __('ui.cat.'.$t['category']) }} · {{ $t['duration_label'] }}</div>
                            @endif
                            @if ($t['description'])<p class="small" style="margin:6px 0 0">{{ $t['description'] }}</p>@endif
                            @if ($t['included'])
                                <div class="incl"><span class="xs muted">{{ __('ui.included_title') }}</span>@foreach ($t['included'] as $inc)<span class="chip ok">✓ {{ $inc }}</span>@endforeach</div>
                            @endif
                            @if ($t['extras'])
                                <div class="incl"><span class="xs muted">{{ __('ui.add_to_ritual') }}</span>@foreach ($t['extras'] as $e)<span class="chip grey">+ {{ $e['name'] }} · {{ number_format($e['price'], 0, ',', ' ') }} {{ $cur }}@if ($e['extra_min']) · +{{ $e['extra_min'] }} {{ __('ui.min') }}@endif</span>@endforeach</div>
                            @endif
                            @if ($t['price_group'])<div class="xs muted" style="margin-top:6px">{{ __('ui.formula.group') }} {{ number_format($t['price_group'], 0, ',', ' ') }} {{ $cur }}{{ __('ui.per_person') }}</div>@endif
                        </div>
                        <div class="r">
                            <div class="solo"><b>{{ number_format($t['price_from'], 0, ',', ' ') }} {{ $cur }}</b><span class="xs muted">{{ __('ui.per_person_solo') }}</span></div>
                            @if ($t['price_couple'])<div class="duo"><span class="xs">{{ __('ui.for_two') }}</span><b>{{ number_format($t['price_couple'], 0, ',', ' ') }} {{ $cur }}</b></div>@endif
                            @if ($t['nr_discount_pct'])<div class="xs" style="color:var(--brand);font-weight:600">{{ __('ui.policy.nr_from', ['price' => number_format($t['nr_price_from'], fmod($t['nr_price_from'], 1) ? 2 : 0, ',', ' ').' '.$cur, 'pct' => $t['nr_discount_pct']]) }}</div>@endif
                            <a class="btn sm" href="{{ route('spa.book', [$spa->slug, 'soin' => $t['id']] + $bookParams) }}">{{ __('ui.book') }}</a>
                        </div>
                    </div>
                @endforeach
            </div>
        </section>
        @if ($card['lat'] !== null && $card['lng'] !== null)
        <section class="sec"><h2>{{ __('ui.location') }}</h2>
            <div class="card" style="padding:0;overflow:hidden" wire:ignore>
                <div id="spa-map" data-lat="{{ $card['lat'] }}" data-lng="{{ $card['lng'] }}" data-title="{{ $spa->name }}" style="height:240px;z-index:0"></div>
            </div>
            <p class="muted small" style="margin-top:8px">{{ $card['area'] ? $card['area'].' · ' : '' }}{{ $card['city'] }} · <a href="https://www.google.com/maps/search/?api=1&query={{ $card['lat'] }},{{ $card['lng'] }}" target="_blank" rel="noopener">{{ __('ui.open_in_maps') }}</a></p>
        </section>
        @endif
        @if ($card['practical'])
        <section class="sec" id="practical"><h2>{{ __('ui.practical_title') }}</h2>
            <div class="card practical" style="padding:14px">
                @foreach ($card['practical'] as $row)<span class="b">{{ $row['label'] }}</span><span>{{ $row['value'] }}</span>@endforeach
            </div>
        </section>
        @endif
        <section class="sec" id="reviews"><h2>{{ __('ui.review.section') }}</h2>
            @if ($reviews->isEmpty())
                <p class="muted small">{{ __('ui.review.none_yet') }}</p>
            @else
                <div class="card" style="padding:16px;margin-bottom:12px">
                    <div class="rv-sum"><span class="big">{{ number_format($card['rating'], 1, app()->getLocale() === 'fr' ? ',' : '.', ' ') }}</span><div><div class="stars" style="color:var(--accent,#b8743a)">{{ str_repeat('★', (int) round($card['rating'])) }}{{ str_repeat('☆', 5 - (int) round($card['rating'])) }}</div><div class="small muted">{{ __('ui.reviews', ['count' => $card['reviews_count']]) }} · {{ __('ui.review.all_verified') }}</div></div></div>
                    @if ($criteriaAvg)<div class="rv-crit-sum">@foreach ($criteriaAvg as $k => $v)<span>{{ __('ui.review.criteria.'.$k) }} <b>{{ [1 => '🙁', 2 => '😐', 3 => '😊'][(int) round($v)] }}</b></span>@endforeach</div>@endif
                </div>
                <div class="rv-list">
                @foreach ($reviews as $r)
                    <article class="card rv-item">
                        <div class="rv-head"><span class="stars">{{ str_repeat('★', (int) $r->rating) }}{{ str_repeat('☆', 5 - (int) $r->rating) }}</span><b class="small">{{ $r->author_name }}</b><span class="chip ok xs">✓ {{ __('ui.review.verified') }}</span><span class="sp"></span><span class="xs muted">{{ $r->published_at?->locale(app()->getLocale())->isoFormat('LL') }}</span></div>
                        @if ($r->title)<h4>{{ $r->title }}</h4>@endif
                        <p>{{ $r->body }}</p>
                        @if ($r->liked)<p class="small"><b style="color:var(--ok)">+</b> {{ $r->liked }}</p>@endif
                        @if ($r->improve)<p class="small"><b style="color:var(--bad)">−</b> {{ $r->improve }}</p>@endif
                        @if ($r->photos->isNotEmpty())<div class="rv-ph">@foreach ($r->photos as $ph)@if ($url = $ph->publicUrl())<a href="{{ $url }}" target="_blank" rel="noopener"><img src="{{ $url }}" alt="" loading="lazy"></a>@endif @endforeach</div>@endif
                        @if ($r->replyPublished())<div class="rv-reply"><b>{{ __('ui.review.reply_from', ['spa' => $spa->name]) }}</b>{{ $r->reply }}</div>@endif
                    </article>
                @endforeach
                </div>
            @endif
        </section>
        <section class="sec"><h2>{{ __('ui.hours') }}</h2>
            <div class="card hours" style="padding:14px">
                @foreach (__('ui.days') as $i => $d)
                    <span @class(['b' => $i === now()->dayOfWeekIso - 1])>{{ $d }}</span><span @class(['muted' => empty($card['hours'][$i])])>{{ empty($card['hours'][$i]) ? __('ui.closed') : implode(', ', $card['hours'][$i]) }}</span>
                @endforeach
            </div>
            <p class="muted small" style="margin-top:10px">{{ __('ui.contact_after') }} {{ __('ui.policy.spa_policy', ['label' => \App\Domain\Policy\CancellationPolicy::label((int) $spa->cancellation_hours)]) }}</p>
        </section>
    </div>
    <aside class="side card">
        <div class="xs muted">{{ __('ui.from') }}</div>
        <div style="font-size:26px;font-weight:700">{{ $card['price_from'] ? number_format($card['price_from'], 0, ',', ' ').' '.config('hl.currency') : '—' }} <span class="small muted">{{ __('ui.per_person') }}</span></div>
        <a class="btn full" style="margin-top:12px" href="{{ route('spa.book', [$spa->slug] + $bookParams) }}">{{ __('ui.book') }}</a>
        <div class="divider"></div>
        <div class="small muted">{{ __('ui.no_account') }}</div>
    </aside>
</div>
<div class="mbar">
    <div class="sp"><span class="xs muted">{{ __('ui.from') }}</span><br><b>{{ $card['price_from'] ? number_format($card['price_from'], 0, ',', ' ').' '.config('hl.currency') : '—' }}</b></div>
    <a class="btn" href="{{ route('spa.book', [$spa->slug] + $bookParams) }}">{{ __('ui.book') }}</a>
</div>
</div>
