<x-filament-panels::page>
    @include('filament.shared.billing.invoice', ['invoice' => $this->record, 'blockers' => $this->blockers(), 'bookingUrl' => fn (int $id) => $this->bookingUrl($id)])
</x-filament-panels::page>
