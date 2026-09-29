<div>
<form class="card search" style="box-shadow:none;margin-top:6px" wire:submit="$refresh">
    <div class="fld"><label>{{ __('ui.search_where') }}</label><input class="inp" wire:model.live.debounce.400ms="q" placeholder="{{ __('ui.search_where_ph') }}"></div>
    <div class="fld"><label>{{ __('ui.search_what') }}</label>
        <select class="inp" wire:model.live="category"><option value="">{{ __('ui.any_treatment') }}</option>@foreach (__('ui.cat') as $k => $v)<option value="{{ $k }}">{{ $v }}</option>@endforeach</select></div>
    <div class="fld"><label>{{ __('ui.search_when') }}</label><input class="inp" type="date" wire:model.live="date" min="{{ now()->format('Y-m-d') }}"></div>
    <button class="btn" type="submit">{{ __('ui.search_btn') }}</button>
</form>
<div class="fchips">
    <a class="chip {{ $category === '' ? 'on' : '' }}" href="{{ route('search', array_filter(['q' => $q, 'date' => $searchDate])) }}">{{ __('ui.any_treatment') }}</a>
    @foreach (__('ui.cat') as $k => $v)<a class="chip {{ $category === $k ? 'on' : '' }}" href="{{ route('search', array_filter(['q' => $q, 'category' => $k, 'date' => $searchDate])) }}">{{ $v }}</a>@endforeach
</div>
<div class="layout" style="margin-top:8px">
    <aside class="filters card" style="padding:14px">
        <h4>{{ __('ui.sort_by') }}</h4>
        @foreach (__('ui.sort') as $k => $v)<label><input type="radio" wire:model.live="sort" value="{{ $k }}"> {{ $v }}</label>@endforeach
        <h4>{{ __('ui.search_what') }}</h4>
        <label><input type="radio" wire:model.live="category" value=""> {{ __('ui.any_treatment') }}</label>
        @foreach (__('ui.cat') as $k => $v)<label><input type="radio" wire:model.live="category" value="{{ $k }}"> {{ $v }}</label>@endforeach
    </aside>
    <div>
        <div class="row wrapg" style="margin:6px 0 12px;align-items:flex-end">
            <h2 class="sp" style="font-size:20px">{{ $q ? __('ui.results_for', ['q' => $q]) : __('ui.all_results') }} <span class="muted small">— {{ trans_choice('ui.spas', $spas->count()) }}</span></h2>
            <label class="sortsel small"><span class="muted">{{ __('ui.sort_by') }}</span>
                <select class="inp" wire:model.live="sort">@foreach (__('ui.sort') as $k => $v)<option value="{{ $k }}">{{ $v }}</option>@endforeach</select></label>
        </div>
        @if ($searchDate)<div class="notice ok small" style="margin-bottom:12px">{{ __('ui.open_on_date', ['date' => \Carbon\CarbonImmutable::parse($searchDate)->translatedFormat('l j F Y')]) }} <a href="{{ route('search', array_filter(['q' => $q, 'category' => $category, 'sort' => $sort !== 'recommended' ? $sort : null])) }}">{{ __('ui.clear_date') }}</a></div>@endif
        @if ($spas->isEmpty())<div class="notice">{{ $searchDate ? __('ui.no_results_date') : __('ui.no_results') }}</div>@endif
        <div class="rescards">@foreach ($spas as $s)@include('livewire.site._spa-card', ['s' => $s, 'searchDate' => $searchDate])@endforeach</div>
    </div>
</div>
</div>
