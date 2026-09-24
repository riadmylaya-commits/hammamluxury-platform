<div>
@php($cur = config('hl.currency'))
@php($fmt = fn ($n) => number_format($n, 0, ',', ' ').' '.$cur)
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
        <h2 style="font-size:20px;margin-bottom:10px">{{ __('ui.choose_treatment') }}</h2>
        @foreach ($treatments as $t)
            <button type="button" class="opt" aria-pressed="{{ $treatment === $t['id'] ? 'true' : 'false' }}" wire:click="selectTreatment({{ $t['id'] }})">
                <div><b>{{ $t['name'] }}</b> @if ($t['is_package'])<span class="chip acc">{{ __('ui.package') }}</span>@endif<div class="muted small">{{ $t['duration_min'] }} {{ __('ui.min') }} · {{ __('ui.cat.'.$t['category']) }}</div></div>
                <div class="r"><b>{{ $fmt($t['price_from']) }}</b><span class="xs muted">{{ __('ui.per_person') }}</span></div>
            </button>
        @endforeach
    @endif

    <div class="divider"></div>
    <div class="row"><b>{{ __('ui.party') }}</b><span class="sp"></span>
        <div class="qty"><button type="button" wire:click="changeParty(-1)" @disabled($party <= 1)>−</button><b>{{ $party }}</b><button type="button" wire:click="changeParty(1)">+</button></div></div>
    @if ($treatment || $advanced)
        <button type="button" class="lnk small" style="background:none;border:0;color:var(--brand);text-decoration:underline;padding:8px 0;cursor:pointer" wire:click="toggleAdvanced">{{ $advanced ? __('ui.simple_toggle') : __('ui.advanced_toggle') }}</button>
    @endif

    @if ($advanced)
        @foreach ($participants as $i => $p)
            <div class="card" style="padding:12px;margin-top:10px">
                <b>{{ __('ui.person_n', ['n' => $i + 1]) }}</b>
                <select class="inp" style="margin-top:6px" wire:change="setParticipantTreatment({{ $i }}, $event.target.value)">
                    @foreach ($treatments as $t)<option value="{{ $t['id'] }}" @selected($p['treatment'] === $t['id'])>{{ $t['name'] }} — {{ $fmt($t['price_from']) }} · {{ $t['duration_min'] }} {{ __('ui.min') }}</option>@endforeach
                </select>
                @php($pt = $treatments->firstWhere('id', $p['treatment']))
                @if ($pt && $pt['extras'])
                    <div class="muted xs" style="margin:8px 0 4px">{{ __('ui.extras') }}</div>
                    @foreach ($pt['extras'] as $e)
                        <button type="button" class="opt" style="padding:9px 12px" aria-pressed="{{ isset($p['extras'][$e['id']]) ? 'true' : 'false' }}" wire:click="toggleExtra({{ $e['id'] }}, {{ $i }})">
                            <div><b>{{ $e['name'] }}</b>@if (isset($p['extras'][$e['id']]) && $e['max_qty'] > 1) ×{{ $p['extras'][$e['id']] }}@endif</div>
                            <div class="r"><b>+{{ $fmt($e['price']) }}</b>@if ($e['extra_min'])<span class="xs muted">+{{ $e['extra_min'] }} {{ __('ui.min') }}</span>@endif</div>
                        </button>
                    @endforeach
                @endif
            </div>
        @endforeach
    @elseif ($treatment)
        @php($ct = $treatments->firstWhere('id', $treatment))
        <div class="divider"></div>
        <h3 style="font-size:17px;margin-bottom:8px">{{ __('ui.extras') }}</h3>
        @forelse ($ct['extras'] as $e)
            <button type="button" class="opt" aria-pressed="{{ isset($extras[$e['id']]) ? 'true' : 'false' }}" wire:click="toggleExtra({{ $e['id'] }})">
                <div><b>{{ $e['name'] }}</b>@if (isset($extras[$e['id']]) && $e['max_qty'] > 1) ×{{ $extras[$e['id']] }}@endif<div class="muted small">@if ($e['per_person']){{ __('ui.extra_each') }}@endif @if ($e['extra_min'])· +{{ $e['extra_min'] }} {{ __('ui.min') }}@endif</div></div>
                <div class="r"><b>+{{ $fmt($e['price']) }}</b></div>
            </button>
        @empty
            <div class="muted small">{{ __('ui.no_extras') }}</div>
        @endforelse
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
        <label class="small row" style="align-items:flex-start"><input type="checkbox" wire:model="terms" style="margin-top:3px"> <span>{{ __('ui.accept_terms') }}</span></label>@error('terms')<span class="ferr">{{ $message }}</span>@enderror
        <div class="notice info">{{ __('ui.no_payment', ['hours' => config('hl.waiting_ttl_hours')]) }} {{ __('ui.no_account') }}</div>
        <div class="row"><button type="button" class="btn ghost" wire:click="back">{{ __('ui.back') }}</button><button type="submit" class="btn acc sp" wire:loading.attr="disabled"><span wire:loading.remove wire:target="submit">{{ __('ui.submit') }}</span><span wire:loading wire:target="submit">{{ __('ui.submitting') }}</span></button></div>
    </form>
@endif
</div>

{{-- ===== Récapitulatif ===== --}}
@if ($quote['ok'])
@php($recap = view('livewire.site._recap', ['quote' => $quote, 'spa' => $spa, 'date' => $date, 'time' => $time, 'fmt' => $fmt])->render())
<aside class="card recap desk">{!! $recap !!}</aside>
<div class="stickytot mobile-only">
    <details><summary class="row" style="list-style:none;cursor:pointer"><div class="sp"><b>{{ $fmt($quote['total']) }}</b> <span class="muted small">· {{ $quote['party'] }} pers. · {{ $quote['duration_min'] }} {{ __('ui.min') }}</span></div><span class="chip grey">{{ __('ui.recap') }}</span></summary>
    <div style="padding-top:10px">{!! $recap !!}</div></details>
</div>
@endif
</div>
</div>
