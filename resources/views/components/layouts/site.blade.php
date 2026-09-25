<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title>{{ isset($title) ? $title.' · ' : '' }}{{ config('app.name') }}</title>
    <meta name="description" content="{{ $description ?? __('ui.hero_sub') }}">
    @foreach (config('hl.locales') as $l)
        <link rel="alternate" hreflang="{{ $l }}" href="{{ url()->current() === url('/') ? url('/'.$l) : preg_replace('#^'.preg_quote(url('/'), '#').'/[a-z]{2}#', url('/'.$l), url()->current()) }}">
    @endforeach
    <link rel="stylesheet" href="{{ asset('css/hl.css') }}?v={{ filemtime(public_path('css/hl.css')) }}">
</head>
<body>
@php($dark = $dark ?? false)
<header @class(['hero' => $dark])>
    <div class="wrap">
        <nav class="nav {{ $dark ? 'dark' : '' }}">
            <a class="logo" href="{{ route('home') }}">HammamLuxury<small>{{ __('ui.tagline') }}</small></a>
            <span class="sp"></span>
            <a class="lnk desk-only" href="{{ route('filament.partner.auth.register') }}">{{ __('ui.nav_list') }}</a>
            <a class="lnk ghost desk-only" href="{{ route('filament.partner.auth.login') }}">{{ __('ui.nav_partner') }}</a>
            @php($other = app()->getLocale() === 'fr' ? 'en' : 'fr')
            <a class="lang" href="{{ preg_replace('#^'.preg_quote(url('/'), '#').'/[a-z]{2}#', url('/'.$other), url()->full()) }}" hreflang="{{ $other }}">{{ strtoupper($other) }}</a>
        </nav>
        {{ $hero ?? '' }}
    </div>
</header>
<main class="wrap">{{ $slot }}</main>
<footer><div class="wrap foot">
    <div><b>HammamLuxury</b>{{ __('ui.footer_about') }}</div>
    <div><b>{{ __('ui.footer_partner') }}</b><a href="{{ route('filament.partner.auth.register') }}">{{ __('ui.nav_list') }}</a><a href="{{ route('filament.partner.auth.login') }}">{{ __('ui.nav_partner') }}</a></div>
    <div><b>{{ __('ui.footer_help') }}</b><a href="#">FAQ</a><a href="#">Contact</a></div>
    <div><b>{{ __('ui.footer_legal') }}</b><a href="#">CGU</a><a href="#">Confidentialité</a></div>
</div></footer>
<script>
(function () {
    var el = document.getElementById('spa-map');
    if (!el) return;
    var css = document.createElement('link'); css.rel = 'stylesheet'; css.href = 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.css'; document.head.appendChild(css);
    var js = document.createElement('script'); js.src = 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.js';
    js.onload = function () {
        var ll = [parseFloat(el.dataset.lat), parseFloat(el.dataset.lng)];
        var map = L.map(el, { scrollWheelZoom: false }).setView(ll, 15);
        L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', { maxZoom: 19, attribution: '&copy; OpenStreetMap' }).addTo(map);
        L.marker(ll).addTo(map).bindPopup(el.dataset.title);
    };
    document.head.appendChild(js);
})();
</script>
</body>
</html>
