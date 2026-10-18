<x-filament-panels::page>
    @php($rows = $this->credits())
    @php($tz = config('hl.timezone'))
    <x-filament::section>
        @if ($rows->isEmpty())
            <p class="text-sm text-gray-500">{{ __('billing.no_credits') }}</p>
        @else
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="text-left text-gray-500">
                            <th style="padding:.25rem .75rem .25rem 0">#</th>
                            <th style="padding:.25rem .75rem .25rem 0">{{ __('billing.partner') }}</th>
                            <th style="padding:.25rem .75rem .25rem 0">{{ __('billing.credit_source') }}</th>
                            <th class="text-right" style="padding:.25rem .75rem .25rem 0">{{ __('billing.amount') }}</th>
                            <th class="text-right" style="padding:.25rem .75rem .25rem 0">{{ __('billing.credit_remaining') }}</th>
                            <th style="padding:.25rem .75rem .25rem 0">{{ __('billing.credit_history') }}</th>
                            <th style="padding:.25rem 0 .25rem 1rem">{{ __('partner.status') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($rows as $c)
                            @php($invoice = $c->source === 'credit_note' ? $c->creditNote?->invoice : $c->overpayment?->invoice)
                            <tr class="border-t border-gray-100 dark:border-gray-800 align-top">
                                <td class="font-mono" style="padding:.5rem .75rem .5rem 0">{{ $c->id }}<div class="text-xs text-gray-500">{{ $c->created_at->setTimezone($tz)->format('d/m/Y') }}</div></td>
                                <td style="padding:.5rem .75rem .5rem 0">{{ $c->partner->company_name }}</td>
                                <td style="padding:.5rem .75rem .5rem 0">{{ __('billing.credit_source_'.$c->source) }} · <span class="font-mono">{{ $c->sourceLabel() }}</span>
                                    @if ($invoice)<div class="text-xs"><a class="underline" href="{{ $this->invoiceUrl($invoice->id) }}">{{ __('billing.invoice') }} {{ $invoice->number }}</a></div>@endif</td>
                                <td class="text-right" style="padding:.5rem .75rem .5rem 0">{{ number_format($c->amount, 2, ',', ' ') }} {{ $c->currency }}</td>
                                <td class="text-right font-semibold {{ $c->remaining() > 0 ? 'text-success-600' : '' }}" style="padding:.5rem .75rem .5rem 0">{{ number_format($c->remaining(), 2, ',', ' ') }} {{ $c->currency }}</td>
                                <td style="padding:.5rem .75rem .5rem 0">
                                    @forelse ($c->applications as $a)
                                        <div class="text-xs">{{ $a->decided_at->setTimezone($tz)->format('d/m/Y H:i') }} · {{ __('billing.credit_kind_'.$a->kind) }} · <strong>{{ number_format($a->amount, 2, ',', ' ') }} {{ $c->currency }}</strong>
                                            · @if ($a->isRefund()){{ $a->refund_on?->format('d/m/Y') }} · {{ __('billing.refund_method_'.$a->refund_method) }} · {{ $a->refund_reference }}@if ($a->note) · {{ $a->note }}@endif
                                            @else<a class="underline" href="{{ $this->invoiceUrl($a->invoice_id) }}">{{ $a->invoice?->number }}</a>@endif
                                            · {{ $a->decidedBy?->name }}</div>
                                    @empty
                                        <span class="text-xs text-gray-500">—</span>
                                    @endforelse
                                </td>
                                <td style="padding:.5rem 0 .5rem 1rem"><x-filament::badge :color="$c->status === 'open' ? 'success' : 'gray'">{{ __('billing.credit_status_'.$c->status) }}</x-filament::badge>
                                    @if ($c->closed_at)<div class="text-xs text-gray-500">{{ $c->closedBy?->name }} · {{ $c->closed_at->setTimezone($tz)->format('d/m/Y H:i') }}@if ($c->note) · {{ $c->note }}@endif</div>@endif
                                    @if ($c->isOpen())
                                        <div class="mt-1">{{ ($this->applyCreditAction)(['credit' => $c->id]) }}</div>
                                        <div class="mt-1">{{ ($this->refundCreditAction)(['credit' => $c->id]) }}</div>
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
