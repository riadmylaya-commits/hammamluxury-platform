<x-mail::message>
# {{ __('emails.billing.'.$notification->event.'.title', $vars) }}

{{ __('emails.billing.'.$notification->event.'.intro', $vars) }}

@if($notification->event === 'statement_available')
**{{ __('billing.period') }} :** {{ $vars['period'] }}
**{{ __('billing.total_billable') }} :** {{ $vars['total'] }}

{{ __('emails.billing.statement_available.window', ['day' => app(\App\Domain\Billing\BillingSettings::class)->get('dispute_until_day')]) }}
@endif
@if($invoice)
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
