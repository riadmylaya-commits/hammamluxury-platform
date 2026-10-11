<x-filament-panels::page>
    <form wire:submit="save" class="space-y-6">
        {{ $this->form }}
        <div class="flex items-center gap-3">
            <x-filament::button type="submit" icon="heroicon-o-check" wire:loading.attr="disabled">{{ __('partner.offline_submit') }}</x-filament::button>
            <span class="text-sm text-gray-500">{{ __('partner.offline_submit_help') }}</span>
        </div>
    </form>
</x-filament-panels::page>
