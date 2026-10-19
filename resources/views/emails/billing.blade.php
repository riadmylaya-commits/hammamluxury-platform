<x-mail::message>
# {{ __('emails.billing.'.$notification->event.'.title', $vars) }}

{{ __('emails.billing.'.$notification->event.'.intro', $vars) }}

@if($notification->event === 'statement_available')
**{{ __('billing.period') }} :** {{ $vars['period'] }}
**{{ __('billing.total_billable') }} :** {{ $vars['total'] }}

{{ __('emails.billing.statement_available.window', ['day' => app(\App\Domain\Billing\BillingSettings::class)->get('dispute_until_day')]) }}
@endif
@if($note)
**{{ __('billing.credit_note_number') }} :** {{ $note->number }}
**{{ __('billing.invoice_number') }} :** {{ $vars['invoice'] }}
@if($notification->event === 'credit_note_pdf_failed')
**{{ __('billing.pdf_error') }} :** {{ $vars['error'] }}
@else
**{{ __('billing.credit_note_total') }} :** {{ $vars['total'] }}
**{{ __('billing.balance') }} :** {{ $vars['balance'] }}
@if($vars['reverses'])
**{{ __('billing.credit_note_reverses') }} :** {{ $vars['reverses'] }}
@endif
@endif
@elseif($invoice && in_array($notification->event, ['credit_applied', 'credit_refunded'], true))
**{{ __('billing.invoice_number') }} :** {{ $invoice->number }}
**{{ __('billing.credit_source') }} :** {{ $vars['source'] }}
**{{ __('billing.amount') }} :** {{ $vars['amount'] }}
@if($notification->event === 'credit_applied')
**{{ __('billing.balance') }} :** {{ $vars['balance'] }}
@else
**{{ __('billing.refund_on') }} :** {{ $vars['refund_on'] }}
**{{ __('billing.method') }} :** {{ $vars['method'] }}
**{{ __('billing.reference') }} :** {{ $vars['reference'] }}
@endif
**{{ __('billing.credit_remaining') }} :** {{ $vars['remaining'] }}
@elseif($invoice && $notification->event === 'corrections_overdue')
**{{ __('billing.invoice_number') }} :** {{ $invoice->number }}
**{{ __('booking.reference') }} :** {{ $vars['reference'] }}
**{{ __('billing.amount') }} :** {{ $vars['amount'] }}
@elseif($invoice)
**{{ __('billing.invoice_number') }} :** {{ $invoice->number }}
**{{ __('billing.period') }} :** {{ $vars['period'] ?: $invoice->period }}
**{{ __('billing.total_ttc') }} :** {{ $vars['total'] }}
@if($notification->event === 'invoice_issued')
**{{ __('billing.due_at') }} :** {{ $vars['due'] }}
@elseif($notification->event === 'invoice_pdf_failed')
**{{ __('billing.pdf_error') }} :** {{ $vars['error'] }}
@elseif(in_array($notification->event, ['payment_partial', 'payment_received', 'overpayment_detected'], true))
**{{ __('billing.amount_received') }} :** {{ $vars['amount'] }}
**{{ __('billing.balance') }} :** {{ $vars['balance'] }}
@if($vars['receipt'])
**{{ __('billing.receipt') }} :** {{ $vars['receipt'] }}
@endif
@elseif($notification->event === 'receipt_pdf_failed')
**{{ __('billing.receipt') }} :** {{ $vars['receipt'] }}
@else
**{{ __('billing.due_at') }} :** {{ $vars['due'] }}
**{{ __('billing.balance') }} :** {{ $vars['balance'] }}
@endif
@endif
@if($dispute)
**{{ __('booking.reference') }} :** {{ $dispute->booking?->reference }}
**{{ __('billing.spa') }} :** {{ $dispute->spa?->name }}
**{{ __('billing.period') }} :** {{ $dispute->period }}
**{{ __('billing.reason') }} :** {{ __('billing.reason_'.$dispute->reason_code) }}
@if($notification->event === 'dispute_opened')
**{{ __('billing.partner') }} :** {{ $vars['partner'] }}
**{{ __('billing.comment') }} :** {{ $dispute->comment }}
@else
**{{ __('billing.decision') }} :** {{ $vars['decision'] }}
**{{ __('billing.decision_note') }} :** {{ $dispute->decision_note }}
@endif
@endif

{{ __('emails.billing.footer') }}

{{ config('app.name') }}
</x-mail::message>
