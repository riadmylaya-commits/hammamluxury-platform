@php
    $fmt = fn ($v) => number_format((float) $v, 2, ',', ' ').' '.$invoice->currency;
    $tz = config('hl.timezone');
@endphp
<div class="space-y-6">
    <x-filament::section>
        <dl class="grid grid-cols-2 gap-x-6 gap-y-2 text-sm sm:grid-cols-4">
            <div><dt class="text-gray-500">{{ __('billing.invoice_number') }}</dt><dd class="font-mono font-semibold">{{ $invoice->label() }}</dd></div>
            <div><dt class="text-gray-500">{{ __('partner.status') }}</dt><dd><x-filament::badge :color="$invoice->isDraft() ? 'gray' : 'warning'">{{ __('billing.invoice_status_'.$invoice->status) }}</x-filament::badge></dd></div>
            <div><dt class="text-gray-500">{{ __('billing.period') }}</dt><dd>{{ $invoice->period }}</dd></div>
            <div><dt class="text-gray-500">{{ __('billing.partner') }}</dt><dd>{{ $invoice->partner_snapshot['company_name'] ?? $invoice->partner->company_name }}</dd></div>
            <div><dt class="text-gray-500">{{ __('billing.issued_at') }}</dt><dd>{{ $invoice->issued_at?->setTimezone($tz)->format('d/m/Y H:i') ?? '—' }}</dd></div>
            <div><dt class="text-gray-500">{{ __('billing.due_at') }}</dt><dd>{{ $invoice->due_at?->format('d/m/Y') ?? '—' }}</dd></div>
            <div><dt class="text-gray-500">{{ __('billing.pdf_status') }}</dt><dd>{{ $invoice->pdf_status ? __('billing.pdf_'.$invoice->pdf_status) : '—' }}@if ($invoice->pdf_error) <span class="text-danger-600">· {{ $invoice->pdf_error }}</span>@endif</dd></div>
            <div><dt class="text-gray-500">{{ __('billing.issued_by') }}</dt><dd>{{ $invoice->issuedBy?->name ?? '—' }}</dd></div>
        </dl>
    </x-filament::section>

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
