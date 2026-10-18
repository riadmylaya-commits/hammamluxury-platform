<x-filament-panels::page>
    @php($rows = $this->corrections())
    @php($due = $this->dueDays())
    @php($tz = config('hl.timezone'))
    <x-filament::section>
        @if ($rows->isEmpty())
            <p class="text-sm text-gray-500">{{ __('billing.corrections_none') }}</p>
        @else
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="text-left text-gray-500">
                            <th style="padding:.25rem .75rem .25rem 0">{{ __('booking.reference') }}</th>
                            <th style="padding:.25rem .75rem .25rem 0">{{ __('billing.partner') }}</th>
                            <th style="padding:.25rem .75rem .25rem 0">{{ __('billing.invoice_number') }}</th>
                            <th style="padding:.25rem .75rem .25rem 0">{{ __('billing.pdf_description') }}</th>
                            <th class="text-right" style="padding:.25rem .75rem .25rem 0">{{ __('billing.amount') }}</th>
                            <th style="padding:.25rem 0 .25rem 1rem">{{ __('billing.correction_age') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($rows as $c)
                            @php($e = $c['entry'])
                            <tr class="border-t border-gray-100 dark:border-gray-800 align-top">
                                <td class="font-mono" style="padding:.5rem .75rem .5rem 0">@if ($e->booking_id)<a class="underline" href="{{ $this->bookingUrl($e->booking_id) }}">{{ $e->booking?->reference ?? $e->booking_reference }}</a>@else{{ $e->booking_reference }}@endif
                                    <div class="text-xs text-gray-500">{{ $e->spa?->name ?? $e->booking?->spa?->name }}</div></td>
                                <td style="padding:.5rem .75rem .5rem 0">{{ $e->partner?->company_name }}</td>
                                <td class="font-mono" style="padding:.5rem .75rem .5rem 0"><a class="underline" href="{{ $this->invoiceUrl($c['invoice']->id) }}">{{ $c['invoice']->number }}</a>
                                    <div class="text-xs text-gray-500">{{ __('billing.invoice_status_'.$c['invoice']->status) }} · {{ __('billing.balance') }} {{ number_format($c['invoice']->balance, 2, ',', ' ') }} {{ $c['invoice']->currency }}</div></td>
                                <td style="padding:.5rem .75rem .5rem 0">{{ __('billing.line_'.$e->type, ['status' => $e->booking?->status ? __('booking.status.'.$e->booking->status) : '']) }}
                                    <div class="text-xs text-gray-500">{{ $e->created_at->setTimezone($tz)->format('d/m/Y H:i') }}@if ($c['dispute']) · {{ __('billing.correction_from_dispute', ['id' => $c['dispute']->id]) }}@endif</div></td>
                                <td class="text-right font-semibold text-danger-600" style="padding:.5rem .75rem .5rem 0">{{ number_format(-$e->amount, 2, ',', ' ') }} {{ $c['invoice']->currency }}</td>
                                <td style="padding:.5rem 0 .5rem 1rem"><x-filament::badge :color="$c['age_days'] >= $due ? 'danger' : 'gray'">{{ __('billing.correction_age_days', ['days' => $c['age_days']]) }}</x-filament::badge>
                                    @if ($c['age_days'] >= $due)<div class="text-xs text-danger-600">{{ __('billing.correction_overdue', ['days' => $due]) }}</div>@endif</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-filament::section>
</x-filament-panels::page>
