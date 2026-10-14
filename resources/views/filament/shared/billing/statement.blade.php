{{-- Relevé provisoire : $statement (Statement), $canDispute (bool), $bookingUrl (Closure|null) --}}
@php($cur = config('hl.currency'))
@php($fmt = fn ($v) => number_format((float) $v, 2, ',', ' ').' '.$cur)
<div class="space-y-6">
    <x-filament::section icon="heroicon-o-information-circle">
        <p class="text-sm">{{ __('billing.statement_notice') }}</p>
        <p class="mt-1 text-sm text-gray-500">
            {{ $statement->closed ? __('billing.period_closed', ['date' => $statement->acquiredUntil->translatedFormat('d/m/Y')]) : __('billing.period_running', ['date' => $statement->acquiredUntil->translatedFormat('d/m/Y H:i')]) }}
            @if ($statement->disputeUntil)
                · {{ $statement->disputeWindowOpen() ? __('billing.dispute_window_open', ['date' => $statement->disputeUntil->translatedFormat('d/m/Y')]) : __('billing.dispute_window_closed', ['date' => $statement->disputeUntil->translatedFormat('d/m/Y')]) }}
            @endif
        </p>
    </x-filament::section>

    @if ($statement->isEmpty())
        <x-filament::section><p class="text-sm text-gray-500">{{ __('billing.statement_empty') }}</p></x-filament::section>
    @endif

    @foreach ($statement->spas as $section)
        <x-filament::section :heading="$section['spa']?->name ?? '—'" :description="__('billing.subtotal').' : '.$fmt($section['total'])" icon="heroicon-o-building-storefront">
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="text-left text-xs uppercase text-gray-500">
                        <tr><th class="py-1 pr-3" style="padding:.25rem .75rem .25rem 0">{{ __('booking.reference') }}</th><th class="py-1 pr-3" style="padding:.25rem .75rem .25rem 0">{{ __('billing.service_date') }}</th><th class="py-1 pr-3" style="padding:.25rem .75rem .25rem 0">{{ __('partner.status') }}</th><th class="py-1 pr-3 text-right" style="padding:.25rem .75rem .25rem 0">{{ __('billing.base') }}</th><th class="py-1 pr-3 text-right" style="padding:.25rem .75rem .25rem 0">{{ __('billing.rate') }}</th><th class="py-1 pr-3 text-right" style="padding:.25rem .75rem .25rem 0">{{ __('billing.commission') }}</th><th class="py-1 pl-4 pr-3" style="padding:.25rem .75rem .25rem 1rem">{{ __('billing.origin_period') }}</th>@if ($canDispute)<th class="py-1" style="padding:.25rem 0 .25rem 0"></th>@endif</tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-white/10">
                        @foreach ($section['lines'] as $line)
                            <tr>
                                <td class="py-2 pr-3 font-mono" style="padding:.5rem .75rem .5rem 0">@if ($bookingUrl && $line['booking_id'])<a class="text-primary-600 underline" href="{{ $bookingUrl($line['booking_id']) }}">{{ $line['reference'] }}</a>@else{{ $line['reference'] }}@endif</td>
                                <td class="py-2 pr-3" style="padding:.5rem .75rem .5rem 0">{{ $line['service_at']?->translatedFormat('d/m/Y H:i') ?? '—' }}</td>
                                <td class="py-2 pr-3" style="padding:.5rem .75rem .5rem 0">{{ $line['status'] ? (__('ui.status')[$line['status']] ?? $line['status']) : '—' }}</td>
                                <td class="py-2 pr-3 text-right" style="padding:.5rem .75rem .5rem 0">{{ $line['base'] !== null ? $fmt($line['base']) : '—' }}</td>
                                <td class="py-2 pr-3 text-right" style="padding:.5rem .75rem .5rem 0">{{ $line['rate'] !== null ? rtrim(rtrim(number_format((float) $line['rate'], 2, '.', ''), '0'), '.').' %' : '—' }}</td>
                                <td class="py-2 pr-3 text-right font-semibold" style="padding:.5rem .75rem .5rem 0">{{ $fmt($line['amount']) }}@if (count($line['entries']) > 1)<span class="block text-xs font-normal text-gray-500">{{ __('billing.with_corrections', ['n' => count($line['entries']) - 1]) }}</span>@endif</td>
                                <td class="py-2 pl-4 pr-3" style="padding:.5rem .75rem .5rem 1rem">{{ $line['origin_period'] }}@if ($line['carried'])<span class="block text-xs text-warning-600">{{ __('billing.carried_over') }}</span>@endif</td>
                                @if ($canDispute)
                                    <td class="py-2 text-right" style="padding:.5rem 0 .5rem 0">
                                        @if ($line['booking_id'])
                                            <x-filament::button size="xs" color="gray" icon="heroicon-o-flag" wire:click="mountAction('dispute', { booking: {{ $line['booking_id'] }} })">{{ __('billing.dispute_action') }}</x-filament::button>
                                        @endif
                                    </td>
                                @endif
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </x-filament::section>
    @endforeach

    @if ($statement->spas !== [])
        <x-filament::section>
            <p class="text-base font-semibold">{{ __('billing.total_billable') }} : {{ $fmt($statement->total) }}</p>
            <p class="text-xs text-gray-500">{{ __('billing.total_help') }}</p>
        </x-filament::section>
    @endif

    @if ($statement->excluded !== [])
        <x-filament::section :heading="__('billing.excluded_title')" :description="__('billing.excluded_help').' · '.$fmt($statement->excludedTotal)" icon="heroicon-o-pause-circle" collapsible>
            <ul class="space-y-1 text-sm">
                @foreach ($statement->excluded as $line)
                    <li><span class="font-mono">{{ $line['reference'] }}</span> · {{ $line['spa_name'] }} · {{ $line['service_at']?->translatedFormat('d/m/Y') }} · {{ $fmt($line['amount']) }} · <span class="text-warning-600">{{ __('billing.exclusion_'.$line['exclusion']) }}</span></li>
                @endforeach
            </ul>
        </x-filament::section>
    @endif
</div>
