<article class="card prose" style="margin:16px auto 32px;max-width:820px;padding:24px 20px">
    <h1>{{ __("pages.$key.title") }}</h1>
    <p class="muted small">{{ __('pages.updated', ['date' => \Carbon\CarbonImmutable::parse(config('hl.legal_updated_at'))->translatedFormat('j F Y')]) }}</p>
    <div class="notice info small" style="margin:12px 0 18px">{{ __('pages.draft_notice') }}</div>
    @include($content)
</article>
