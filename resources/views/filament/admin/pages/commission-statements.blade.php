<x-filament-panels::page>
    <div class="flex flex-wrap gap-4">
        <div class="w-full max-w-sm">
            <label class="mb-1 block text-sm font-medium" for="partnerId">{{ __('billing.partner') }}</label>
            <x-filament::input.wrapper>
                <x-filament::input.select id="partnerId" wire:model.live="partnerId">
                    <option value="">—</option>
                    @foreach ($this->partnerOptions() as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </x-filament::input.select>
            </x-filament::input.wrapper>
        </div>
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
    </div>

    @php($statement = $this->statement())
    @if ($statement === null)
        <x-filament::section><p class="text-sm text-gray-500">{{ __('billing.choose_partner') }}</p></x-filament::section>
    @else
        @if ($statement->blockers !== [])
            <x-filament::section :heading="__('billing.blockers_title')" icon="heroicon-o-lock-closed" collapsible collapsed>
                <ul class="list-disc space-y-1 pl-5 text-sm">
                    @foreach ($statement->blockers as $code)
                        <li>{{ __('billing.blocker.'.$code) }}</li>
                    @endforeach
                </ul>
            </x-filament::section>
        @endif
        @include('filament.shared.billing.statement', ['statement' => $statement, 'canDispute' => false, 'bookingUrl' => fn (int $id) => $this->bookingUrl($id)])
    @endif
</x-filament-panels::page>
