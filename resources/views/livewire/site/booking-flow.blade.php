<div>
@php($cur = config('hl.currency'))
@php($fmt = fn ($n) => number_format($n, fmod((float) $n, 1) ? 2 : 0, ',', ' ').' '.$cur)
<div class="row" style="margin-top:6px"><a class="lnk small" href="{{ route('spa.show', $spa->slug) }}">← {{ $spa->name }}</a></div>
<div class="steps">
    @foreach ([1 => 'step_treatment', 2 => 'step_datetime', 3 => 'step_details'] as $n => $label)
        <span @if ($step === $n) data-on @elseif ($step > $n) data-done @endif><i>{{ $step > $n ? '✓' : $n }}</i><span class="desk-only" style="display:inline">{{ __('ui.'.$label) }}</span></span>@if ($n < 3)<hr>@endif
    @endforeach
</div>
@if ($error)<div class="notice bad" style="margin-bottom:12px" role="alert">{{ $error }}</div>@endif

<div class="book-layout">
<div>
{{-- ===== Étape 1 ===== --}}
@if ($step === 1)
    @if (! $advanced)
        @php($sel = $treatment ? $treatments->firstWhere('id', $treatment) : null)
        @if ($sel)
            <div class="sel-treat" role="status" aria-live="polite">
                <div class="sp">
                    <div class="xs muted" style="text-transform:uppercase;letter-spacing:.04em">{{ __('ui.selected_treatment') }}</div>
                    <b style="font-size:17px">{{ $sel['short_name'] }}</b> @if ($sel['is_package'])<span class="chip acc">{{ __('ui.package') }}</span>@endif
                    <div class="muted small" style="margin-top:2px">@if ($sel['components']){{ $sel['components'] }} · {{ __('ui.total_duration') }} {{ $sel['duration_label'] }}@else{{ $sel['duration_label'] }} · {{ __('ui.cat.'.$sel['category']) }}@endif</div>
                    <p class="sel-msg">{{ __('ui.selected_msg') }}</p>
                </div>
                <div class="r">@if ($sel['fixed_party'])<b>{{ $fmt($sel['fixed_party_total']) }}</b><span class="xs muted" style="display:block">{{ __('ui.for_party', ['n' => $sel['fixed_party']]) }}</span>@else<b>{{ $fmt($sel['price_from']) }}</b><span class="xs muted">{{ __('ui.per_person') }}</span>@if ($sel['price_couple'])<span class="xs" style="display:block;color:var(--brand);font-weight:600">{{ __('ui.for_two_short') }} : {{ $fmt($sel['price_couple']) }}</span>@endif@endif</div>
            </div>
            <button type="button" class="btn discover full" wire:click="toggleList" aria-expanded="{{ $showAll ? 'true' : 'false' }}" aria-controls="treatment-list">{{ $showAll ? '▲ '.__('ui.hide_all_treatments') : '▼ '.__('ui.show_all_treatments') }}</button>
            @if (! $showAll)<div class="xs muted" style="margin-top:6px;text-align:center">{{ __('ui.keep_booking_hint') }}</div>@endif
        @endif
        @if (! $sel || $showAll)
        <div id="treatment-list" @if ($sel) style="margin-top:16px" @endif>
        <h2 style="font-size:20px;margin-bottom:10px">{{ $sel ? __('ui.change_treatment_q') : __('ui.choose_treatment') }}</h2>
        @php($prevCat = null)
        @foreach ($treatments as $t)
            @if ($t['category'] !== $prevCat)
                @php($prevCat = $t['category'])
                <h3 class="cat-h">{{ __('ui.cat_group.'.$t['category']) }}</h3>
            @endif
            <button type="button" class="opt" aria-pressed="{{ $treatment === $t['id'] ? 'true' : 'false' }}" wire:click="selectTreatment({{ $t['id'] }})">
                <div>@if ($t['badge'])<span class="chip badge xs" style="margin-bottom:4px">★ {{ $t['badge'] }}</span><br>@endif<b>{{ $t['short_name'] }}</b> @if ($t['is_package'])<span class="chip acc">{{ __('ui.package') }}</span>@endif
                    <div class="muted small">@if ($t['components']){{ $t['components'] }} · {{ __('ui.total_duration') }} {{ $t['duration_label'] }}@else{{ $t['duration_label'] }} · {{ __('ui.cat.'.$t['category']) }}@endif</div>
                    @if ($t['included'])<div class="xs muted" style="margin-top:3px">{{ __('ui.included_title') }} : {{ implode(', ', $t['included']) }}</div>@endif</div>
                <div class="r">@if ($t['fixed_party'])<b>{{ $fmt($t['fixed_party_total']) }}</b><span class="xs muted" style="display:block">{{ __('ui.for_party', ['n' => $t['fixed_party']]) }}</span>@else<b>{{ $fmt($t['price_from']) }}</b><span class="xs muted">{{ __('ui.per_person') }}</span>@if ($t['price_couple'])<span class="xs" style="display:block;color:var(--brand);font-weight:600">{{ __('ui.for_two_short') }} : {{ $fmt($t['price_couple']) }}</span>@endif@endif</div>
            </button>
        @endforeach
        </div>
        @endif
    @endif

    @if ($quote['ok'] && ($treatment || $advanced) && \App\Domain\Policy\CancellationPolicy::nrEnabled())
        <div class="divider"></div>
        <h3 style="font-size:16px;margin-bottom:8px">{{ __('ui.policy.rate_title') }}</h3>
        <div class="rates">
            <button type="button" class="opt rate" aria-pressed="{{ $rate !== 'non_refundable' ? 'true' : 'false' }}" wire:click="setRate('standard')">
                <div><b>{{ __('ui.policy.standard') }}</b><div class="xs muted">{{ $quote['cancellation_hours'] === null ? __('ui.policy.standard_desc_to_confirm') : __('ui.policy.standard_desc', ['label' => \App\Domain\Policy\CancellationPolicy::label($quote['cancellation_hours'])]) }}</div></div>
                <div class="r"><b>{{ $fmt($quote['standard_total']) }}</b></div>
            </button>
            @if ($this->nrAvailable())
                @php($nrPct = collect($quote['lines'])->pluck('nr_discount_pct')->filter()->min() ?? collect($treatments)->whereIn('id', $advanced ? array_column($participants, 'treatment') : [$treatment])->pluck('nr_discount_pct')->filter()->min())
                <button type="button" class="opt rate" aria-pressed="{{ $rate === 'non_refundable' ? 'true' : 'false' }}" wire:click="setRate('non_refundable')">
                    <div><span class="chip acc xs">{{ __('ui.policy.non_refundable') }} · −{{ $nrPct }} %</span><div class="xs muted" style="margin-top:4px">{{ __('ui.policy.nr_desc', ['pct' => $nrPct]) }}</div></div>
                    <div class="r"><b>{{ $rate === 'non_refundable' ? $fmt($quote['total']) : $fmt(\App\Domain\Policy\CancellationPolicy::discounted($quote['standard_total'] - collect($quote['lines'])->sum('extras_price'), $nrPct) + collect($quote['lines'])->sum('extras_price')) }}</b></div>
                </button>
            @endif
        </div>
    @endif

    <div class="divider"></div>
    <div class="row"><b>{{ __('ui.party') }}</b><span class="sp"></span>
        <div class="qty"><button type="button" wire:click="changeParty(-1)" @disabled($party <= 1)>−</button><b>{{ $party }}</b><button type="button" wire:click="changeParty(1)">+</button></div></div>
    @if (($treatment || $advanced) && $party >= 2)
        <div class="mode" role="group" aria-label="{{ __('ui.mode_title') }}">
            <div class="xs muted" style="margin-bottom:6px">{{ __('ui.mode_title') }}</div>
            <div class="seg">
                <button type="button" aria-pressed="{{ $advanced ? 'false' : 'true' }}" wire:click="setMode('same')">{{ __('ui.mode_same') }}</button>
                <button type="button" aria-pressed="{{ $advanced ? 'true' : 'false' }}" wire:click="setMode('each')">{{ __('ui.mode_each') }}</button>
            </div>
        </div>
    @endif

    @if ($advanced)
        @foreach ($participants as $i => $p)
            @php($pt = $p['treatment'] ? $treatments->firstWhere('id', $p['treatment']) : null)
            <div class="pcard">
                <div class="pcard-h"><span class="person-no">{{ $i + 1 }}</span><b>{{ __('ui.person_n', ['n' => $i + 1]) }}</b><button type="button" class="btn mini" wire:click="openPicker({{ $i }})">{{ $pt ? __('ui.edit_treatment') : __('ui.choose_treatment') }}</button></div>
                @if ($pt)
                    <div class="pcard-name">{{ $pt['short_name'] }} @if ($pt['is_package'])<span class="chip acc xs">{{ __('ui.package') }}</span>@endif</div>
                    <div class="pcard-meta">{{ $pt['duration_label'] }} · {{ $fmt($pt['price_from']) }}</div>
                    @if ($pt['components'] || $pt['description'])<div class="pcard-sum">{{ $pt['components'] ?: \Illuminate\Support\Str::limit($pt['description'], 110) }}</div>@endif
                    @if ($pt['extras'])
                        <div class="pcard-extras">
                            @foreach ($pt['extras'] as $e)
                                <button type="button" class="xchip" aria-pressed="{{ isset($p['extras'][$e['id']]) ? 'true' : 'false' }}" wire:click="toggleExtra({{ $e['id'] }}, {{ $i }})">{{ isset($p['extras'][$e['id']]) ? '✓ ' : '+ ' }}{{ $e['name'] }}@if (isset($p['extras'][$e['id']]) && $e['max_qty'] > 1) ×{{ $p['extras'][$e['id']] }}@endif · {{ $fmt($e['price']) }}@if ($e['extra_min']) · +{{ $e['extra_min'] }} {{ __('ui.min') }}@endif</button>
                            @endforeach
                        </div>
                    @endif
                @else
                    <div class="pcard-name muted">{{ __('ui.to_choose') }}</div>
                @endif
            </div>
        @endforeach

        @if ($picker !== null && isset($participants[$picker]))
            @php($cur = $participants[$picker]['treatment'])
            <div class="sheet-bg" wire:click.self="closePicker" role="dialog" aria-modal="true" aria-labelledby="picker-title">
                <div class="sheet">
                    <div class="sheet-h"><b id="picker-title">{{ __('ui.choose_for_person', ['n' => $picker + 1]) }}</b><button type="button" class="sheet-x" aria-label="{{ __('ui.close') }}" wire:click="closePicker">×</button></div>
                    <div class="sheet-b">
                        @php($prevCat = null)
                        @foreach ($treatments->where('party_min', '<=', 1) as $t)
                            @if ($t['category'] !== $prevCat)
                                @php($prevCat = $t['category'])
                                <h3 class="cat-h">{{ __('ui.cat_group.'.$t['category']) }}</h3>
                            @endif
                            <button type="button" class="pick" aria-pressed="{{ $cur === $t['id'] ? 'true' : 'false' }}" wire:click="pickTreatment({{ $picker }}, {{ $t['id'] }})">
                                <span class="pick-main"><b>{{ $t['short_name'] }}</b>@if ($t['is_package']) <span class="chip acc xs">{{ __('ui.package') }}</span>@endif<span class="pick-meta">{{ $t['duration_label'] }} · {{ $fmt($t['price_from']) }}</span>@if ($t['components'])<span class="pick-sum">{{ $t['components'] }}</span>@elseif ($t['description'])<span class="pick-sum">{{ \Illuminate\Support\Str::limit($t['description'], 90) }}</span>@endif</span>
                                <span class="pick-check" aria-hidden="true">{{ $cur === $t['id'] ? '✓' : '' }}</span>
                            </button>
                        @endforeach
                        @if ($treatments->where('party_min', '>', 1)->isNotEmpty())<div class="xs muted" style="margin-top:12px">{{ __('ui.fixed_party_note') }}</div>@endif
                    </div>
                    <div class="sheet-f"><button type="button" class="btn full" wire:click="closePicker" @disabled(! $cur)>{{ __('ui.validate_choice') }}</button></div>
                </div>
            </div>
        @endif
    @elseif ($treatment)
        @php($ct = $treatments->firstWhere('id', $treatment))
        <div class="divider"></div>
        <h3 style="font-size:17px;margin-bottom:2px">{{ __('ui.add_to_ritual') }}</h3>
        @if ($ct['extras'])<div class="muted small" style="margin-bottom:8px">{{ __('ui.add_to_ritual_help') }}</div>@endif
        @forelse ($ct['extras'] as $e)
            <button type="button" class="opt" aria-pressed="{{ isset($extras[$e['id']]) ? 'true' : 'false' }}" wire:click="toggleExtra({{ $e['id'] }})">
                <div><b>{{ $e['name'] }}</b>@if (isset($extras[$e['id']]) && $e['max_qty'] > 1) ×{{ $extras[$e['id']] }}@endif<div class="muted small">@if ($e['per_person']){{ __('ui.extra_each') }}@endif @if ($e['extra_min'])· +{{ $e['extra_min'] }} {{ __('ui.min') }}@endif</div></div>
                <div class="r"><b>+{{ $fmt($e['price']) }}</b></div>
            </button>
        @empty
            <div class="muted small">{{ __('ui.no_extras') }}</div>
        @endforelse
    @endif

    @if ($quote['ok'] && ($advanced || $party >= 2))
        @php($sum = $advanced ? collect($participants)->map(fn ($p) => $treatments->firstWhere('id', $p['treatment'])) : collect(array_fill(0, 1, $treatments->firstWhere('id', $treatment))))
        <div class="mini-recap">
            <div class="mini-h"><b>{{ __('ui.mini_recap') }}</b><span class="chip grey">{{ trans_choice('ui.persons', $quote['party'], ['n' => $quote['party']]) }}</span></div>
            @foreach ($sum as $i => $st)
                @if ($st)<div class="mini-l"><span>@if ($advanced){{ $i + 1 }}. @endif{{ $st['short_name'] }}@if (! $advanced && $quote['party'] > 1) ×{{ $quote['party'] }}@endif</span><b>{{ $st['duration_label'] }}</b></div>@endif
            @endforeach
            <div class="mini-t"><span>{{ __('ui.recap_total') }}</span><b>{{ $fmt($quote['total']) }}</b></div>
        </div>
    @endif
    @if (($treatment || $advanced) && ! $quote['ok'] && ! empty($quote['errors']))
        <div class="notice bad small" style="margin-top:14px" role="alert">{{ implode(' ', $quote['errors']) }}</div>
    @endif
    <div style="margin-top:18px"><button type="button" class="btn full" wire:click="next" @disabled(! $quote['ok'])>{{ __('ui.continue') }} → {{ __('ui.step_datetime') }}</button></div>

{{-- ===== Étape 2 ===== --}}
@elseif ($step === 2)
    <h2 style="font-size:20px;margin-bottom:10px">{{ __('ui.pick_date') }}</h2>
    <div class="card" style="padding:12px">
        <div class="calnav"><button type="button" wire:click="shiftMonth(-1)">‹</button><b>{{ \Carbon\CarbonImmutable::parse($month.'-01')->locale(app()->getLocale())->isoFormat('MMMM YYYY') }}</b><button type="button" wire:click="shiftMonth(1)">›</button></div>
        <div class="cal">
            @foreach (__('ui.days_short') as $d)<div class="h">{{ $d }}</div>@endforeach
            @foreach ($calendar as $c)
                @if (! $c)<div></div>
                @else<button type="button" class="d" aria-pressed="{{ $date === $c['date'] ? 'true' : 'false' }}" @disabled($c['past'] || ! $c['open']) wire:click="pickDate('{{ $c['date'] }}')">{{ $c['day'] }}</button>@endif
            @endforeach
        </div>
    </div>
    @if (! $date && $spa->hours->isEmpty())
        <div class="notice" style="margin-top:12px"><b>{{ __('ui.no_slots_at_all') }}</b><br>{{ __('booking.not_configured') }}</div>
    @elseif (! $date && ! collect($calendar)->contains(fn ($c) => $c && $c['open'] && ! $c['past']))
        <div class="notice" style="margin-top:12px"><b>{{ __('ui.no_slots_month') }}</b><br><span class="muted">{{ __('ui.try_other_month') }}</span></div>
    @endif
    @if ($date)
        <h3 style="font-size:17px;margin:18px 0 8px">{{ __('ui.pick_time') }} — {{ \Carbon\CarbonImmutable::parse($date)->locale(app()->getLocale())->isoFormat('dddd D MMMM') }}</h3>
        <div wire:loading wire:target="pickDate" class="muted small">{{ __('ui.loading_slots') }}</div>
        <div wire:loading.remove wire:target="pickDate">
        @if ($slots)
            <div class="slots">@foreach ($slots as $s)<button type="button" aria-pressed="{{ $time === $s ? 'true' : 'false' }}" wire:click="pickTime('{{ $s }}')">{{ $s }}</button>@endforeach</div>
            @if ($time)<div class="muted small" style="margin-top:8px">{{ __('ui.ends_at', ['time' => \Carbon\CarbonImmutable::parse("$date $time")->addMinutes($quote['duration_min'])->format('H:i')]) }} · {{ $quote['duration_min'] }} {{ __('ui.min') }}</div>@endif
        @else
            <div class="notice"><b>{{ __('ui.no_slots') }}</b><br>{{ $slotsReason }}<br><span class="muted">{{ __('ui.try_other') }}</span></div>
        @endif
        </div>
    @endif
    <div class="row" style="margin-top:18px"><button type="button" class="btn ghost" wire:click="back">{{ __('ui.back') }}</button><button type="button" class="btn sp" wire:click="next" @disabled(! $date || ! $time)><span wire:loading.remove wire:target="next">{{ __('ui.continue') }} → {{ __('ui.step_details') }}</span><span wire:loading wire:target="next">{{ __('ui.loading_slots') }}</span></button></div>

{{-- ===== Étape 3 ===== --}}
@else
    <h2 style="font-size:20px;margin-bottom:10px">{{ __('ui.your_details') }}</h2>
    <form wire:submit="submit" class="card" style="padding:14px;display:grid;gap:12px">
        <div class="grid2">
            <div class="fld"><label>{{ __('ui.first_name') }}</label><input class="inp @error('first_name') err @enderror" wire:model="first_name" autocomplete="given-name" required>@error('first_name')<span class="ferr">{{ $message }}</span>@enderror</div>
            <div class="fld"><label>{{ __('ui.last_name') }}</label><input class="inp @error('last_name') err @enderror" wire:model="last_name" autocomplete="family-name" required>@error('last_name')<span class="ferr">{{ $message }}</span>@enderror</div>
        </div>
        <div class="fld"><label>{{ __('ui.email') }}</label><input class="inp @error('email') err @enderror" type="email" wire:model="email" autocomplete="email" inputmode="email" required>@error('email')<span class="ferr">{{ $message }}</span>@enderror</div>
        <div class="fld"><label>{{ __('ui.phone') }}</label>
            <div style="display:grid;grid-template-columns:minmax(0,1.6fr) minmax(0,2fr);gap:8px">
                <select class="inp @error('phoneCountry') err @enderror" wire:model="phoneCountry" autocomplete="tel-country-code" aria-label="{{ __('phone.country') }}">@foreach (\App\Domain\Phone\PhoneNumber::options() as $iso => $lbl)<option value="{{ $iso }}">{{ $lbl }}</option>@endforeach</select>
                <input class="inp @error('phone') err @enderror" type="tel" wire:model="phone" autocomplete="tel-national" inputmode="tel" placeholder="0661351989" aria-label="{{ __('phone.number') }}" required>
            </div>
            <span class="xs muted">{{ __('phone.help') }}</span>
            @error('phone')<span class="ferr">{{ $message }}</span>@enderror
        </div>
        <div class="fld"><label>{{ __('ui.hotel') }}</label><input class="inp" wire:model="hotel"></div>
        <div class="fld"><label>{{ __('ui.note') }}</label><textarea class="inp" rows="2" wire:model="note"></textarea><span class="xs muted">{{ __('ui.note_privacy') }}</span></div>
        <div class="notice {{ ($quote['rate'] ?? 'standard') === 'non_refundable' ? 'warn' : 'info' }}" id="policy"><b>{{ __('ui.policy.title') }}</b> — @if (($quote['rate'] ?? 'standard') === 'non_refundable'){{ __('ui.policy.nr_booking', ['pct' => $quote['nr_discount_pct'], 'amount' => $fmt($quote['total'])]) }}@else{{ ($quote['cancellation_hours'] ?? null) === null ? __('ui.policy.standard_desc_to_confirm') : __('ui.policy.standard_desc', ['label' => \App\Domain\Policy\CancellationPolicy::label($quote['cancellation_hours'])]) }} @if (($quote['cancellation_hours'] ?? null) !== null) {{ __('ui.policy.free_until', ['date' => \Carbon\CarbonImmutable::parse("$date $time")->subHours($quote['cancellation_hours'])->locale(app()->getLocale())->isoFormat('LLL')]) }}@endif @endif</div>
        <label class="small row" style="align-items:flex-start"><input type="checkbox" wire:model="terms" style="margin-top:3px"> <span>{!! __('ui.accept_terms', ['url' => route('page', 'cgu')]) !!}</span></label>@error('terms')<span class="ferr">{{ $message }}</span>@enderror
        @if ($spa->instantBookingActive())
            <div class="notice info"><b>{{ __('ui.instant_title') }}</b> {{ __('ui.instant_help') }} {{ __('ui.no_account') }}</div>
        @else
            <div class="notice info">{{ $spa->isReferenced() ? __('ui.no_payment_referenced', ['hours' => $spa->waitingTtlHours(), 'open' => config('hl.admin_hours')[0], 'close' => config('hl.admin_hours')[1]]) : __('ui.no_payment', ['hours' => $spa->waitingTtlHours()]) }} {{ __('ui.no_account') }}</div>
        @endif
        <div class="row"><button type="button" class="btn ghost" wire:click="back">{{ __('ui.back') }}</button><button type="submit" class="btn acc sp" wire:loading.attr="disabled"><span wire:loading.remove wire:target="submit">{{ $spa->instantBookingActive() ? __('ui.submit_instant') : __('ui.submit') }}</span><span wire:loading wire:target="submit">{{ __('ui.submitting') }}</span></button></div>
    </form>
@endif
</div>

{{-- ===== Récapitulatif ===== --}}
@if ($quote['ok'])
@php($recap = view('livewire.site._recap', ['quote' => $quote, 'spa' => $spa, 'date' => $date, 'time' => $time, 'fmt' => $fmt])->render())
<aside class="card recap desk">{!! $recap !!}</aside>
<div @class(['stickytot', 'mobile-only', 'inflow' => $step === 1])>
    <details><summary class="row" style="list-style:none;cursor:pointer"><div class="sp"><b>{{ $fmt($quote['total']) }}</b> <span class="muted small">· {{ $quote['party'] }} pers. · {{ $quote['duration_min'] }} {{ __('ui.min') }}</span></div><span class="chip grey">{{ __('ui.recap') }}</span></summary>
    <div style="padding-top:10px">{!! $recap !!}</div></details>
</div>
@endif
</div>
</div>
