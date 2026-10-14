<x-filament-panels::page>
    @php($invoices = $this->invoices())
    @php($tz = config('hl.timezone'))
    @if ($invoices->isEmpty())
        <x-filament::section><p class="text-sm text-gray-500">{{ __('billing.no_invoices') }}</p></x-filament::section>
    @else
        <x-filament::section>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="text-left text-gray-500">
                            <th style="padding:.25rem .75rem .25rem 0">{{ __('billing.invoice_number') }}</th>
                            <th style="padding:.25rem .75rem .25rem 0">{{ __('billing.period') }}</th>
                            <th style="padding:.25rem .75rem .25rem 0">{{ __('billing.issued_at') }}</th>
                            <th style="padding:.25rem .75rem .25rem 0">{{ __('billing.due_at') }}</th>
                            <th class="text-right" style="padding:.25rem .75rem .25rem 0">{{ __('billing.total_ttc') }}</th>
                            <th style="padding:.25rem 0 .25rem 1rem">{{ __('partner.status') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($invoices as $i)
                            <tr class="border-t border-gray-100 dark:border-gray-800 {{ $selected === $i->id ? 'bg-primary-50 dark:bg-primary-950' : '' }}">
                                <td class="font-mono" style="padding:.5rem .75rem .5rem 0"><button type="button" class="underline" wire:click="select({{ $i->id }})">{{ $i->number }}</button></td>
                                <td style="padding:.5rem .75rem .5rem 0">{{ $i->period }}</td>
                                <td style="padding:.5rem .75rem .5rem 0">{{ $i->issued_at->setTimezone($tz)->format('d/m/Y') }}</td>
                                <td style="padding:.5rem .75rem .5rem 0">{{ $i->due_at->format('d/m/Y') }}</td>
                                <td class="text-right font-semibold" style="padding:.5rem .75rem .5rem 0">{{ number_format($i->total_ttc, 2, ',', ' ') }} {{ $i->currency }}</td>
                                <td style="padding:.5rem 0 .5rem 1rem"><x-filament::badge color="warning">{{ __('billing.invoice_status_'.$i->status) }}</x-filament::badge>
                                    @if ($i->pdf_status !== 'ready')<span class="text-xs text-gray-500"> · {{ __('billing.pdf_pending') }}</span>@endif</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </x-filament::section>
    @endif

    @if ($invoice = $this->invoice())
        <div class="flex justify-end">{{ $this->pdfAction }}</div>
        @include('filament.shared.billing.invoice', ['invoice' => $invoice, 'blockers' => [], 'bookingUrl' => null])
    @endif

    <x-filament-actions::modals />
</x-filament-panels::page>
