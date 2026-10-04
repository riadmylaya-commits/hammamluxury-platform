<x-filament-panels::page.simple>
    @php($user = auth()->user())

    @if ($recoveryCodes)
        <div class="rounded-lg border border-amber-300 bg-amber-50 p-4 text-sm dark:border-amber-700 dark:bg-amber-950">
            <p class="font-semibold">{{ __('security.recovery_title') }}</p>
            <p class="mt-1">{{ __('security.recovery_intro') }}</p>
            <ul class="mt-3 grid grid-cols-2 gap-1 font-mono text-base">
                @foreach ($recoveryCodes as $code)
                    <li>{{ $code }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    @if ($user->hasTwoFactor())
        <div class="rounded-lg border border-green-300 bg-green-50 p-4 text-sm dark:border-green-700 dark:bg-green-950">
            <p class="font-semibold">{{ __('security.active_title') }}</p>
            <p class="mt-1">{{ __('security.active_since', ['date' => $user->two_factor_confirmed_at?->translatedFormat('d F Y H:i')]) }}</p>
            <p class="mt-1">{{ __('security.recovery_remaining', ['count' => count($user->two_factor_recovery_codes ?? [])]) }}</p>
        </div>
        <div class="flex flex-wrap items-center gap-3">
            {{ $this->regenerateAction }}
            @if ($this->disableAction->isVisible())
                {{ $this->disableAction }}
            @endif
            <x-filament::button tag="a" href="{{ $this->continueUrl() }}" color="primary">{{ __('security.continue') }}</x-filament::button>
        </div>
    @else
        @if ($user->mustEnableTwoFactor())
            <div class="rounded-lg border border-amber-300 bg-amber-50 p-4 text-sm dark:border-amber-700 dark:bg-amber-950">
                {{ __('security.required_admin') }}
            </div>
        @endif

        <ol class="list-decimal space-y-2 pl-5 text-sm text-gray-700 dark:text-gray-300">
            <li>{{ __('security.step_app') }}</li>
            <li>{{ __('security.step_scan') }}</li>
            <li>{{ __('security.step_code') }}</li>
        </ol>

        <div class="flex flex-col items-center gap-3">
            <div class="rounded-lg bg-white p-2">{!! $this->qrSvg(app(\App\Domain\Security\TwoFactor::class)) !!}</div>
            <p class="text-xs text-gray-500">{{ __('security.manual_key') }} <code class="select-all font-mono text-sm">{{ $secret }}</code></p>
        </div>

        <x-filament-panels::form id="form" wire:submit="enable">
            {{ $this->form }}
            <x-filament::button type="submit" class="w-full">{{ __('security.enable') }}</x-filament::button>
        </x-filament-panels::form>

        @unless ($user->mustEnableTwoFactor())
            <div class="text-center">
                <x-filament::link href="{{ $this->continueUrl() }}" color="gray" size="sm">{{ __('security.later') }}</x-filament::link>
            </div>
        @endunless
    @endif

    <x-filament-actions::modals />
</x-filament-panels::page.simple>
