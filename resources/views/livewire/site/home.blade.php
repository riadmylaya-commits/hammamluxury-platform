<div>
<x-slot:dark>1</x-slot:dark>
<x-slot:hero>
    <h1>{{ __('ui.hero_title') }}</h1>
    <p>{{ __('ui.hero_sub') }}</p>
    <form class="search" method="get" action="{{ route('search') }}">
        <div class="fld"><label>{{ __('ui.search_where') }}</label><input class="inp" name="q" value="{{ $q }}" placeholder="{{ __('ui.search_where_ph') }}" autocomplete="off"></div>
        <div class="fld"><label>{{ __('ui.search_what') }}</label>
            <select class="inp" name="category"><option value="">{{ __('ui.any_treatment') }}</option>@foreach (__('ui.cat') as $k => $v)<option value="{{ $k }}">{{ $v }}</option>@endforeach</select></div>
        <div class="fld"><label>{{ __('ui.search_when') }}</label><input class="inp" type="date" name="date" min="{{ now()->format('Y-m-d') }}"></div>
        <button class="btn" type="submit">{{ __('ui.search_btn') }}</button>
    </form>
</x-slot:hero>

<div class="trust">
    @foreach (__('ui.trust') as $i => [$t, $d])
        <div><i>{{ ['✓', '☺', '★', '⚡'][$i] }}</i><div><b>{{ $t }}</b><span class="muted small">{{ $d }}</span></div></div>
    @endforeach
</div>

@if ($cities->isNotEmpty())
<section class="sec"><h2>{{ __('ui.popular_cities') }}</h2>
    <div class="hscroll">
        @foreach ($cities as $i => $c)
            <a class="city" href="{{ route('search', ['q' => $c->city]) }}"><div class="ph {{ ['', 'green', 'sand', 'blue'][$i % 4] }}"><span>{{ $c->city }} · {{ trans_choice('ui.spas', $c->n) }}</span></div></a>
        @endforeach
    </div>
</section>
@endif

<section class="sec"><h2>{{ __('ui.browse_cat') }}</h2>
    <div class="hscroll">
        @foreach (['hammam' => 'H', 'massage' => 'M', 'face' => 'V', 'ritual' => 'R'] as $k => $letter)
            <a class="cat" href="{{ route('search', ['category' => $k]) }}"><i>{{ $letter }}</i><b>{{ __('ui.cat.'.$k) }}</b></a>
        @endforeach
    </div>
</section>

@if ($featured->isNotEmpty())
<section class="sec"><h2>{{ __('ui.featured') }}</h2>
    <div class="rescards">@foreach ($featured as $s)@include('livewire.site._spa-card', ['s' => $s])@endforeach</div>
</section>
@endif

<section class="sec card" id="partner" style="padding:20px">
    <h2 style="font-size:20px">{{ __('ui.nav_list') }}</h2>
    <p class="muted small">{{ app()->getLocale() === 'fr' ? 'Vous gérez un hammam, un spa ou un centre de bien-être ? Rejoignez HammamLuxury et gérez vous-même vos soins, photos, horaires et réservations.' : 'Do you run a hammam, spa or wellness centre? Join HammamLuxury and manage your treatments, photos, opening hours and bookings yourself.' }}</p>
    <a class="btn sec sm" href="#">{{ __('ui.nav_partner') }}</a>
</section>
</div>
