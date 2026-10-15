@php
    $fmt = fn ($v) => number_format((float) $v, 2, ',', ' ').' '.$payment->currency;
    $issuer = $invoice->issuer_snapshot ?? [];
    $partner = $invoice->partner_snapshot ?? [];
    $tz = config('hl.timezone');
@endphp
<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
<meta charset="utf-8">
<title>{{ $payment->receipt_number }}</title>
<style>
    body { font-family: 'DejaVu Sans', sans-serif; font-size: 10.5px; color: #1f2937; margin: 0; }
    h1 { font-size: 18px; margin: 0 0 2px; color: #7c2d12; }
    .muted { color: #6b7280; }
    table { width: 100%; border-collapse: collapse; }
    .head td { vertical-align: top; width: 50%; padding: 0 0 12px; }
    .box { border: 1px solid #e5e7eb; padding: 8px 10px; }
    .kv td { padding: 4px 6px; border-bottom: 1px solid #f3f4f6; }
    .kv td:first-child { color: #6b7280; width: 45%; }
    .amount { font-size: 16px; font-weight: bold; color: #7c2d12; }
    .foot { position: fixed; bottom: 0; left: 0; right: 0; font-size: 8.5px; color: #6b7280; border-top: 1px solid #e5e7eb; padding-top: 4px; }
</style>
</head>
<body>
<table class="head">
    <tr>
        <td>
            <h1>{{ $issuer['company_name'] ?? 'HammamLuxury' }}</h1>
            <div>{{ $issuer['legal_form'] ?? '' }}</div>
            <div>{!! nl2br(e($issuer['address'] ?? '')) !!}</div>
            @if (!empty($issuer['ice']))<div>ICE : {{ $issuer['ice'] }}</div>@endif
            @if (!empty($issuer['tax_id']))<div>{{ __('billing.pdf_tax_id') }} : {{ $issuer['tax_id'] }}</div>@endif
        </td>
        <td style="text-align:right">
            <div class="muted">{{ __('billing.receipt') }}</div>
            <div style="font-size:14px;font-weight:bold">{{ $payment->receipt_number }}</div>
            <div class="muted">{{ __('billing.receipt_date') }} : {{ $payment->confirmed_at->setTimezone($tz)->format('d/m/Y H:i') }}</div>
        </td>
    </tr>
</table>
<div class="box">
    <div class="muted">{{ __('billing.receipt_received_from') }}</div>
    <div style="font-weight:bold">{{ $partner['company_name'] ?? $invoice->partner?->company_name }}</div>
    <div>{!! nl2br(e($partner['address'] ?? '')) !!}</div>
    @if (!empty($partner['ice']))<div>ICE : {{ $partner['ice'] }}</div>@endif
</div>
<table class="kv" style="margin-top:12px">
    <tr><td>{{ __('billing.amount_received') }}</td><td class="amount">{{ $fmt($payment->amount) }}</td></tr>
    <tr><td>{{ __('billing.method') }}</td><td>{{ __('billing.method_'.$payment->method) }}</td></tr>
    <tr><td>{{ __('billing.paid_on') }}</td><td>{{ $payment->paid_on->format('d/m/Y') }}</td></tr>
    <tr><td>{{ __('billing.invoice_number') }}</td><td>{{ $invoice->number }} — {{ __('billing.period') }} {{ $invoice->period }}</td></tr>
    <tr><td>{{ __('billing.total_ttc') }}</td><td>{{ $fmt($invoice->total_ttc) }}</td></tr>
    <tr><td>{{ __('billing.balance_after') }}</td><td>{{ $fmt($payment->balance_after) }}</td></tr>
    @if ($payment->reference)<tr><td>{{ __('billing.reference') }}</td><td>{{ $payment->reference }}</td></tr>@endif
    <tr><td>{{ __('billing.confirmed_by') }}</td><td>{{ $payment->confirmedBy?->name }}</td></tr>
</table>
<p class="muted" style="margin-top:14px">{{ __('billing.receipt_legal') }}</p>
<div class="foot">{{ $issuer['company_name'] ?? 'HammamLuxury' }} · {{ $payment->receipt_number }} · {{ __('billing.pdf_footer') }}</div>
</body>
</html>
