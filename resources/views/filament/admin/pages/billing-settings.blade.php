<x-filament-panels::page>
    @php($blockers = $this->blockers())
    <x-filament::section :heading="__('billing.blockers_title')" icon="heroicon-o-lock-closed">
        @if ($blockers === [])
            <p class="text-sm text-success-600">{{ __('billing.blockers_none') }}</p>
        @else
            <ul class="list-disc space-y-1 pl-5 text-sm">
                @foreach ($blockers as $code)
                    <li>{{ __('billing.blocker.'.$code) }}</li>
                @endforeach
            </ul>
        @endif
    </x-filament::section>
    <form wire:submit="save" class="space-y-6">
        {{ $this->form }}
        <x-filament::button type="submit" icon="heroicon-o-check" wire:loading.attr="disabled">{{ __('billing.save') }}</x-filament::button>
    </form>
</x-filament-panels::page>
