<x-filament-widgets::widget>
    <x-filament::section :heading="__('partner.config_heading')" :description="$complete ? __('partner.config_complete') : __('partner.config_incomplete')">
        <div class="grid gap-2 sm:grid-cols-2">
            @foreach ($checks as $label => $ok)
                <div class="flex items-start gap-2 text-sm">
                    <x-filament::icon :icon="$ok ? 'heroicon-o-check-circle' : 'heroicon-o-x-circle'" @class(['h-5 w-5 shrink-0', 'text-success-600' => $ok, 'text-danger-600' => ! $ok]) />
                    <span>{{ $label }}</span>
                </div>
            @endforeach
        </div>
        <div class="mt-4 text-sm">
            @if ($spa->instant_booking && $complete)
                <x-filament::badge color="success">{{ __('partner.instant_on') }}</x-filament::badge> {{ __('partner.instant_on_help') }}
            @elseif ($spa->instant_booking)
                <x-filament::badge color="warning">{{ __('partner.instant_suspended') }}</x-filament::badge> {{ __('partner.instant_suspended_help') }}
            @else
                <x-filament::badge color="gray">{{ __('partner.instant_off') }}</x-filament::badge> {{ $complete ? __('partner.instant_ask_admin') : __('partner.instant_off_help') }}
            @endif
        </div>
    </x-filament::section>
</x-filament-widgets::widget>
