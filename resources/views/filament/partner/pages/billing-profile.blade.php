<x-filament-panels::page>
    <form wire:submit="save" class="space-y-6">
        {{ $this->form }}
        <x-filament::button type="submit" icon="heroicon-o-check" wire:loading.attr="disabled">{{ __('billing.save') }}</x-filament::button>
    </form>
    @php($blockers = $this->blockers())
    <x-filament::section :heading="$blockers === [] ? __('billing.billing_ready_yes') : __('billing.billing_ready_no')" icon="heroicon-o-identification">
        @if ($blockers !== [])
            <ul class="list-disc space-y-1 pl-5 text-sm">
                @foreach ($blockers as $code)
                    <li>{{ __('billing.blocker.'.$code) }}</li>
                @endforeach
            </ul>
        @endif
    </x-filament::section>
</x-filament-panels::page>
