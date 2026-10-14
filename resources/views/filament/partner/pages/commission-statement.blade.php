<x-filament-panels::page>
    @php($statement = $this->statement())
    <div class="flex flex-wrap items-end justify-between gap-4">
        <div class="w-full max-w-xs">
            <label class="mb-1 block text-sm font-medium" for="period">{{ __('billing.period') }}</label>
            <x-filament::input.wrapper>
                <x-filament::input.select id="period" wire:model.live="period">
                    @foreach ($this->periodOptions() as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </x-filament::input.select>
            </x-filament::input.wrapper>
        </div>
        <a href="{{ $this->disputesUrl() }}" class="text-sm text-primary-600 underline">{{ __('billing.my_disputes') }} ({{ $this->openDisputesCount() }} {{ __('billing.open_plural') }})</a>
    </div>

    @include('filament.shared.billing.statement', ['statement' => $statement, 'canDispute' => true, 'bookingUrl' => fn (int $id) => $this->bookingUrl($id)])

    <x-filament-actions::modals />
</x-filament-panels::page>
