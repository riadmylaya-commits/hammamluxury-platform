<x-filament-panels::page>
    @include('filament.shared.billing.invoice', ['invoice' => $this->record, 'blockers' => $this->blockers(), 'bookingUrl' => fn (int $id) => $this->bookingUrl($id), 'receiptAction' => $this->receiptAction, 'reverseAction' => $this->reversePaymentAction,
        'creditNoteActions' => ['issue' => $this->issueCreditNoteAction, 'discard' => $this->discardCreditNoteAction, 'reverse' => $this->reverseCreditNoteAction, 'pdf' => $this->creditNotePdfAction]])
</x-filament-panels::page>
