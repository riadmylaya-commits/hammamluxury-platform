@php
    $fmt = fn ($v) => number_format((float) $v, 2, ',', ' ').' '.$invoice->currency;
    $tz = config('hl.timezone');
@endphp
<div class="space-y-6">
    <x-filament::section>
        <dl class="grid grid-cols-2 gap-x-6 gap-y-2 text-sm sm:grid-cols-4">
            <div><dt class="text-gray-500">{{ __('billing.invoice_number') }}</dt><dd class="font-mono font-semibold">{{ $invoice->label() }}</dd></div>
            <div><dt class="text-gray-500">{{ __('partner.status') }}</dt><dd><x-filament::badge :color="$invoice->isDraft() ? 'gray' : ($invoice->status === 'paid' ? 'success' : ($invoice->status === 'overdue' ? 'danger' : 'warning'))">{{ __('billing.invoice_status_'.$invoice->status) }}</x-filament::badge></dd></div>
            <div><dt class="text-gray-500">{{ __('billing.period') }}</dt><dd>{{ $invoice->period }}</dd></div>
            <div><dt class="text-gray-500">{{ __('billing.partner') }}</dt><dd>{{ $invoice->partner_snapshot['company_name'] ?? $invoice->partner->company_name }}</dd></div>
            <div><dt class="text-gray-500">{{ __('billing.issued_at') }}</dt><dd>{{ $invoice->issued_at?->setTimezone($tz)->format('d/m/Y H:i') ?? '—' }}</dd></div>
            <div><dt class="text-gray-500">{{ __('billing.due_at') }}</dt><dd>{{ $invoice->due_at?->format('d/m/Y') ?? '—' }}</dd></div>
            <div><dt class="text-gray-500">{{ __('billing.pdf_status') }}</dt><dd>{{ $invoice->pdf_status ? __('billing.pdf_'.$invoice->pdf_status) : '—' }}@if ($invoice->pdf_error) <span class="text-danger-600">· {{ $invoice->pdf_error }}</span>@endif</dd></div>
            <div><dt class="text-gray-500">{{ __('billing.issued_by') }}</dt><dd>{{ $invoice->issuedBy?->name ?? '—' }}</dd></div>
            @if ($invoice->isIssued())
                <div><dt class="text-gray-500">{{ __('billing.total_ttc') }}</dt><dd class="font-semibold">{{ $fmt($invoice->total_ttc) }}</dd></div>
                <div><dt class="text-gray-500">{{ __('billing.paid_amount') }}</dt><dd>{{ $fmt($invoice->paid_amount) }}</dd></div>
                @if ($invoice->credited_amount > 0)<div><dt class="text-gray-500">{{ __('billing.credited_amount') }}</dt><dd>{{ $fmt($invoice->credited_amount) }}</dd></div>@endif
                <div><dt class="text-gray-500">{{ __('billing.balance') }}</dt><dd class="font-semibold {{ $invoice->balance > 0 ? 'text-warning-600' : 'text-success-600' }}">{{ $fmt($invoice->balance) }}</dd></div>
                <div><dt class="text-gray-500">{{ __('billing.paid_at') }}</dt><dd>{{ $invoice->paid_at?->setTimezone($tz)->format('d/m/Y H:i') ?? '—' }}</dd></div>
            @endif
        </dl>
    </x-filament::section>

    @if ($invoice->isIssued())
        @php($payments = $invoice->payments()->with(['confirmedBy', 'reversedBy', 'overpayment'])->get())
        <x-filament::section :heading="__('billing.payment_history')">
            @if ($payments->isEmpty())
                <p class="text-sm text-gray-500">{{ __('billing.no_payments') }}</p>
            @else
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="text-left text-gray-500">
                                <th style="padding:.25rem .75rem .25rem 0">{{ __('billing.paid_on') }}</th>
                                <th style="padding:.25rem .75rem .25rem 0">{{ __('billing.method') }}</th>
                                <th class="text-right" style="padding:.25rem .75rem .25rem 0">{{ __('billing.amount') }}</th>
                                <th class="text-right" style="padding:.25rem .75rem .25rem 0">{{ __('billing.balance_after') }}</th>
                                <th style="padding:.25rem .75rem .25rem 0">{{ __('billing.reference') }}</th>
                                <th style="padding:.25rem .75rem .25rem 0">{{ __('billing.receipt') }}</th>
                                <th style="padding:.25rem 0 .25rem 1rem">{{ __('billing.confirmed_by') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($payments as $p)
                                <tr class="border-t border-gray-100 dark:border-gray-800 {{ $p->isReversal() ? 'text-gray-500' : '' }}">
                                    <td style="padding:.5rem .75rem .5rem 0">{{ $p->paid_on->format('d/m/Y') }}
                                        @if ($p->isReversal())<div class="text-xs text-danger-600">{{ __('billing.reversal_of', ['id' => $p->reverses_payment_id]) }} · {{ $p->reversal_reason }}</div>@endif
                                        @if ($p->reversedBy)<div class="text-xs text-danger-600">{{ __('billing.reversed') }}</div>@endif
                                        @if ($p->overpayment)<div class="text-xs text-warning-600">{{ __('billing.overpayment') }} : {{ $fmt($p->overpayment->amount) }} · {{ __('billing.overpayment_status_'.$p->overpayment->status) }}</div>@endif
                                    </td>
                                    <td style="padding:.5rem .75rem .5rem 0">{{ __('billing.method_'.$p->method) }}</td>
                                    <td class="text-right font-semibold {{ $p->amount < 0 ? 'text-danger-600' : '' }}" style="padding:.5rem .75rem .5rem 0">{{ $fmt($p->amount) }}</td>
                                    <td class="text-right" style="padding:.5rem .75rem .5rem 0">{{ $fmt($p->balance_after) }}</td>
                                    <td style="padding:.5rem .75rem .5rem 0">{{ $p->reference ?? '—' }}@if ($p->note)<div class="text-xs text-gray-500">{{ $p->note }}</div>@endif</td>
                                    <td style="padding:.5rem .75rem .5rem 0">
                                        @if ($p->receipt_number)
                                            <span class="font-mono">{{ $p->receipt_number }}</span>
                                            @if ($p->receipt_status === 'ready' && ! empty($receiptAction))
                                                <div>{{ $receiptAction(['payment' => $p->id]) }}</div>
                                            @elseif ($p->receipt_status)
                                                <div class="text-xs text-gray-500">{{ __('billing.pdf_'.$p->receipt_status) }}@if ($p->receipt_error) · <span class="text-danger-600">{{ $p->receipt_error }}</span>@endif</div>
                                            @endif
                                        @else
                                            —
                                        @endif
                                    </td>
                                    <td style="padding:.5rem 0 .5rem 1rem">{{ $p->confirmedBy?->name }}<div class="text-xs text-gray-500">{{ $p->confirmed_at->setTimezone($tz)->format('d/m/Y H:i') }}</div>
                                        @if (! empty($reverseAction) && ! $p->isReversal() && ! $p->reversedBy)
                                            <div class="mt-1">{{ $reverseAction(['payment' => $p->id]) }}</div>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </x-filament::section>

        @php($notes = $invoice->creditNotes()->with(['issuedBy', 'reverses', 'reversedBy', 'partnerCredit', 'lines'])->get())
        @php($cnActions = $creditNoteActions ?? [])
        @if ($notes->isNotEmpty())
        <x-filament::section :heading="__('billing.credit_notes')">
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="text-left text-gray-500">
                            <th style="padding:.25rem .75rem .25rem 0">{{ __('billing.credit_note_number') }}</th>
                            <th style="padding:.25rem .75rem .25rem 0">{{ __('partner.status') }}</th>
                            <th style="padding:.25rem .75rem .25rem 0">{{ __('billing.credit_note_reason') }}</th>
                            <th class="text-right" style="padding:.25rem .75rem .25rem 0">{{ __('billing.credit_note_total') }}</th>
                            <th class="text-right" style="padding:.25rem .75rem .25rem 0">{{ __('billing.balance_after') }}</th>
                            <th style="padding:.25rem 0 .25rem 1rem">{{ __('billing.pdf') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($notes as $n)
                            <tr class="border-t border-gray-100 dark:border-gray-800 align-top">
                                <td class="font-mono" style="padding:.5rem .75rem .5rem 0">{{ $n->label() }}
                                    @if ($n->issued_at)<div class="text-xs text-gray-500">{{ $n->issued_at->setTimezone($tz)->format('d/m/Y H:i') }} · {{ $n->issuedBy?->name }}</div>@endif
                                    @if ($n->isReversal())<div class="text-xs text-danger-600">{{ __('billing.credit_note_reverses') }} {{ $n->reverses?->number }}</div>@endif
                                    @if ($n->reversedBy)<div class="text-xs text-danger-600">{{ __('billing.credit_note_status_reversed') }} · {{ $n->reversedBy->number }}</div>@endif
                                    @if ($n->lines->isNotEmpty())<div class="text-xs text-gray-500">{{ $n->lines->pluck('booking_reference')->filter()->implode(', ') }}</div>@endif</td>
                                <td style="padding:.5rem .75rem .5rem 0"><x-filament::badge :color="$n->isDraft() ? 'gray' : ($n->reversedBy ? 'danger' : 'success')">{{ __('billing.credit_note_status_'.($n->reversedBy ? 'reversed' : $n->status)) }}</x-filament::badge>
                                    @if ($n->partnerCredit)<div class="text-xs text-warning-600">{{ __('billing.credit') }} : {{ $fmt($n->partnerCredit->amount) }} · {{ __('billing.credit_status_'.$n->partnerCredit->status) }}</div>@endif</td>
                                <td style="padding:.5rem .75rem .5rem 0">{{ $n->reason }}</td>
                                <td class="text-right font-semibold {{ $n->total_ttc < 0 ? 'text-danger-600' : '' }}" style="padding:.5rem .75rem .5rem 0">{{ $fmt($n->total_ttc) }}</td>
                                <td class="text-right" style="padding:.5rem .75rem .5rem 0">{{ $n->invoice_balance_after !== null ? $fmt($n->invoice_balance_after) : '—' }}</td>
                                <td style="padding:.5rem 0 .5rem 1rem">
                                    @if ($n->isDraft())
                                        @if (! empty($cnActions['issue']))<div>{{ $cnActions['issue'](['note' => $n->id]) }}</div>@endif
                                        @if (! empty($cnActions['discard']))<div class="mt-1">{{ $cnActions['discard'](['note' => $n->id]) }}</div>@endif
                                    @else
                                        @if ($n->pdf_status === 'ready' && ! empty($cnActions['pdf']))
                                            <div>{{ $cnActions['pdf'](['note' => $n->id]) }}</div>
                                        @else
                                            <div class="text-xs text-gray-500">{{ $n->pdf_status ? __('billing.pdf_'.$n->pdf_status) : '—' }}@if ($n->pdf_error) · <span class="text-danger-600">{{ $n->pdf_error }}</span>@endif</div>
                                        @endif
                                        @if (! empty($cnActions['reverse']) && ! $n->isReversal() && ! $n->reversedBy)
                                            <div class="mt-1">{{ $cnActions['reverse'](['note' => $n->id]) }}</div>
                                        @endif
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </x-filament::section>
        @endif
    @endif

    @if ($invoice->isDraft())
        <x-filament::section :heading="__('billing.invoice_blockers')">
            @if ($blockers === [])
                <p class="text-sm text-success-600">{{ __('billing.invoice_ready') }}</p>
            @else
                <ul class="list-disc space-y-1 pl-5 text-sm">
                    @foreach ($blockers as $code)
                        <li>{{ __('billing.blocker.'.$code) }}</li>
                    @endforeach
                </ul>
            @endif
        </x-filament::section>
    @endif

    @foreach ($invoice->sections() as $section)
        <x-filament::section :heading="$section['spa']?->name ?? '—'" :description="__('billing.subtotal_spa').' : '.$fmt($section['total'])">
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="text-left text-gray-500">
                            <th style="padding:.25rem .75rem .25rem 0">{{ __('booking.reference') }}</th>
                            <th style="padding:.25rem .75rem .25rem 0">{{ __('billing.service_date') }}</th>
                            <th style="padding:.25rem .75rem .25rem 0">{{ __('billing.pdf_description') }}</th>
                            <th class="text-right" style="padding:.25rem .75rem .25rem 0">{{ __('billing.base') }}</th>
                            <th class="text-right" style="padding:.25rem .75rem .25rem 0">{{ __('billing.rate') }}</th>
                            <th class="text-right" style="padding:.25rem .75rem .25rem 0">{{ __('billing.commission') }}</th>
                            <th style="padding:.25rem 0 .25rem 1rem">{{ __('billing.origin_period') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($section['lines'] as $line)
                            <tr class="border-t border-gray-100 dark:border-gray-800">
                                <td class="font-mono" style="padding:.5rem .75rem .5rem 0">@if ($bookingUrl && $line->booking_id)<a class="underline" href="{{ $bookingUrl($line->booking_id) }}">{{ $line->booking_reference }}</a>@else{{ $line->booking_reference }}@endif</td>
                                <td style="padding:.5rem .75rem .5rem 0">{{ $line->service_at?->setTimezone($tz)->format('d/m/Y H:i') }}</td>
                                <td style="padding:.5rem .75rem .5rem 0">{{ $line->description }}</td>
                                <td class="text-right" style="padding:.5rem .75rem .5rem 0">{{ $line->base_amount !== null ? $fmt($line->base_amount) : '' }}</td>
                                <td class="text-right" style="padding:.5rem .75rem .5rem 0">{{ $line->commission_pct !== null ? $line->commission_pct.' %' : '' }}</td>
                                <td class="text-right font-semibold" style="padding:.5rem .75rem .5rem 0">{{ $fmt($line->amount) }}</td>
                                <td style="padding:.5rem 0 .5rem 1rem">{{ $line->origin_period }}@if ($line->carried) · {{ __('billing.carried') }}@endif</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </x-filament::section>
    @endforeach

    <x-filament::section>
        <div class="space-y-1 text-right text-sm">
            <p>{{ __('billing.subtotal_ht') }} : {{ $fmt($invoice->subtotal_ht) }}</p>
            @if ($invoice->vat_mode === 'ht_plus_vat')<p>{{ __('billing.vat') }} ({{ $invoice->vat_rate }} %) : {{ $fmt($invoice->vat_amount) }}</p>
            @elseif ($invoice->vat_mode === 'ttc_included')<p>{{ __('billing.vat_included') }} ({{ $invoice->vat_rate }} %) : {{ $fmt($invoice->vat_amount) }}</p>
            @else<p class="text-gray-500">{{ __('billing.vat_none') }}</p>@endif
            <p class="text-base font-semibold">{{ __('billing.total_ttc') }} : {{ $fmt($invoice->total_ttc) }}</p>
        </div>
    </x-filament::section>
</div>
