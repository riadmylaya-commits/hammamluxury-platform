<x-filament-panels::page>
    @php($rows = $this->overpayments())
    @php($tz = config('hl.timezone'))
    <x-filament::section>
        @if ($rows->isEmpty())
            <p class="text-sm text-gray-500">{{ __('billing.no_payments') }}</p>
        @else
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="text-left text-gray-500">
                            <th style="padding:.25rem .75rem .25rem 0">{{ __('billing.invoice_number') }}</th>
                            <th style="padding:.25rem .75rem .25rem 0">{{ __('billing.partner') }}</th>
                            <th style="padding:.25rem .75rem .25rem 0">{{ __('billing.paid_on') }}</th>
                            <th class="text-right" style="padding:.25rem .75rem .25rem 0">{{ __('billing.amount_received') }}</th>
                            <th class="text-right" style="padding:.25rem .75rem .25rem 0">{{ __('billing.overpayment') }}</th>
                            <th style="padding:.25rem 0 .25rem 1rem">{{ __('partner.status') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($rows as $o)
                            <tr class="border-t border-gray-100 dark:border-gray-800">
                                <td class="font-mono" style="padding:.5rem .75rem .5rem 0"><a class="underline" href="{{ $this->invoiceUrl($o->invoice_id) }}">{{ $o->invoice->number }}</a></td>
                                <td style="padding:.5rem .75rem .5rem 0">{{ $o->partner->company_name }}</td>
                                <td style="padding:.5rem .75rem .5rem 0">{{ $o->payment->paid_on->format('d/m/Y') }} · {{ __('billing.method_'.$o->payment->method) }}</td>
                                <td class="text-right" style="padding:.5rem .75rem .5rem 0">{{ number_format($o->payment->amount, 2, ',', ' ') }} {{ $o->currency }}</td>
                                <td class="text-right font-semibold text-warning-600" style="padding:.5rem .75rem .5rem 0">{{ number_format($o->amount, 2, ',', ' ') }} {{ $o->currency }}</td>
                                <td style="padding:.5rem 0 .5rem 1rem"><x-filament::badge :color="$o->status === 'open' ? 'warning' : 'gray'">{{ __('billing.overpayment_status_'.$o->status) }}</x-filament::badge>
                                    @if ($o->decided_at)<div class="text-xs text-gray-500">{{ $o->decidedBy?->name }} · {{ $o->decided_at->setTimezone($tz)->format('d/m/Y H:i') }} · {{ $o->decision_note }}</div>@endif
                                    @if ($o->status === 'open' && $o->partner_credit_id === null)
                                        <div class="mt-1">{{ ($this->openCreditAction)(['overpayment' => $o->id]) }}</div>
                                    @elseif ($o->partner_credit_id !== null)
                                        <div class="text-xs"><a class="underline" href="{{ $this->creditsUrl() }}">{{ __('billing.credit') }} #{{ $o->partner_credit_id }}</a></div>
                                    @endif</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-filament::section>
    <x-filament-actions::modals />
</x-filament-panels::page>
