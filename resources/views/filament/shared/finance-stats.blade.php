{{-- Suivi financier : même rendu que le widget de statistiques de Filament, avec les filtres mois / établissement. --}}
@php($spas = $this->spaOptions())
<x-filament-widgets::widget class="fi-wi-stats-overview grid gap-y-4">
    <div class="fi-wi-stats-overview-header grid gap-y-1">
        <h3 class="fi-wi-stats-overview-header-heading col-span-full text-base font-semibold leading-6 text-gray-950 dark:text-white">{{ $this->getHeading() }}</h3>
        <p class="fi-wi-stats-overview-header-description overflow-hidden break-words text-sm text-gray-500 dark:text-gray-400">{{ $this->getDescription() }}</p>
    </div>

    <div class="flex flex-wrap items-end gap-3">
        <label class="grid gap-y-1 text-sm font-medium text-gray-950 dark:text-white" style="min-width: 12rem; flex: 1 1 12rem; max-width: 20rem">
            {{ __('booking.finance_filter_period') }}
            <x-filament::input.wrapper>
                <x-filament::input.select wire:model.live="period" style="min-height: 44px">
                    @foreach ($this->periodOptions() as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </x-filament::input.select>
            </x-filament::input.wrapper>
        </label>
        @if ($spas)
            <label class="grid gap-y-1 text-sm font-medium text-gray-950 dark:text-white" style="min-width: 12rem; flex: 1 1 12rem; max-width: 24rem">
                {{ __('booking.finance_filter_spa') }}
                <x-filament::input.wrapper>
                    <x-filament::input.select wire:model.live="spaId" style="min-height: 44px">
                        <option value="">{{ __('booking.finance_filter_all_spas') }}</option>
                        @foreach ($spas as $value => $label)
                            <option value="{{ $value }}">{{ $label }}</option>
                        @endforeach
                    </x-filament::input.select>
                </x-filament::input.wrapper>
            </label>
        @endif
    </div>

    <div class="fi-wi-stats-overview-stats-ctn grid gap-6 md:grid-cols-3" wire:loading.class="opacity-60">
        @foreach ($this->getCachedStats() as $stat)
            {{ $stat }}
        @endforeach
    </div>
</x-filament-widgets::widget>
