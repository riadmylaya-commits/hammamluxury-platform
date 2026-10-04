<x-filament-panels::page.simple>
    <x-filament-panels::form id="form" wire:submit="verify">
        {{ $this->form }}
        <x-filament::button type="submit" class="w-full">{{ __('security.verify') }}</x-filament::button>
    </x-filament-panels::form>

    <p class="text-sm text-gray-500 dark:text-gray-400 text-center">
        {{ __('security.recovery_hint') }}
    </p>

    <div class="text-center">
        <x-filament::link wire:click="logout" tag="button" color="gray" size="sm">{{ __('security.logout') }}</x-filament::link>
    </div>
</x-filament-panels::page.simple>
