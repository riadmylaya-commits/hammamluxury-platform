<x-filament-panels::page.simple>
    @php($spa = $this->spa())
    @php($others = $this->otherDrafts())

    @if ($spa)
        <div class="rounded-lg border border-primary-200 bg-primary-50 px-4 py-3 text-sm text-primary-900 dark:border-primary-800 dark:bg-primary-950 dark:text-primary-100">
            <p><strong>{{ __('partner.resume_draft', ['name' => $spa->name]) }}</strong> — {{ __('partner.resume_draft_help') }}</p>
            <p class="mt-1">
                <a href="{{ request()->url() }}?new=1" class="underline">{{ __('partner.start_new_spa') }}</a>
                @foreach ($others as $o)
                    · <a href="{{ request()->url() }}?spa={{ $o->id }}" class="underline">{{ __('partner.resume_other', ['name' => $o->name]) }}</a>
                @endforeach
            </p>
        </div>
        @if ($spa->status_note)
            <div class="rounded-lg border px-4 py-3 text-sm" style="border-color:#fca5a5;background:#fef2f2;color:#991b1b">
                <p><strong>{{ __('partner.refused_banner') }}</strong></p>
                <p class="mt-1 whitespace-pre-line">{{ $spa->status_note }}</p>
                <p class="mt-1">{{ __('partner.refused_banner_help') }}</p>
            </div>
        @endif
    @elseif ($others->isNotEmpty())
        <div class="rounded-lg border border-warning-200 bg-warning-50 px-4 py-3 text-sm text-warning-900">
            {{ __('partner.drafts_pending') }}
            @foreach ($others as $o)
                · <a href="{{ request()->url() }}?spa={{ $o->id }}" class="underline">{{ $o->name }}</a>
            @endforeach
        </div>
    @endif

    <x-filament-panels::form id="form" wire:submit="register">
        {{ $this->form }}
    </x-filament-panels::form>
</x-filament-panels::page.simple>
