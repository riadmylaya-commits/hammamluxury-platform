@php
    $fmt = fn ($v) => number_format((float) $v, 2, ',', ' ').' '.$note->currency;
    $issuer = $note->issuer_snapshot ?? [];
    $partner = $note->partner_snapshot ?? [];
    $invoice = $note->invoice;
@endphp
<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
<meta charset="utf-8">
<title>{{ $note->number }}</title>
<style>
    body { font-family: 'DejaVu Sans', sans-serif; font-size: 10.5px; color: #1f2937; margin: 0; }
    h1 { font-size: 20px; margin: 0 0 2px; color: #7c2d12; }
    .muted { color: #6b7280; }
    table { width: 100%; border-collapse: collapse; }
    .head td { vertical-align: top; width: 50%; padding: 0 0 12px; }
    .box { border: 1px solid #e5e7eb; padding: 8px 10px; }
    .lines th { background: #fdf2e9; text-align: left; padding: 5px 6px; border-bottom: 1px solid #e5e7eb; font-size: 9.5px; }
    .lines td { padding: 4px 6px; border-bottom: 1px solid #f3f4f6; }
    .r { text-align: right; }
    .totals td { padding: 3px 6px; }
    .totals .grand td { font-weight: bold; font-size: 12px; border-top: 2px solid #7c2d12; }
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
            @if (!empty($issuer['registry_number']))<div>{{ __('billing.pdf_registry') }} : {{ $issuer['registry_number'] }}</div>@endif
        </td>
        <td class="r">
            <div style="font-size:16px;font-weight:bold">{{ __($note->isReversal() ? 'billing.pdf_credit_note_reversal' : 'billing.pdf_credit_note') }} {{ $note->number }}</div>
            <div>{{ __('billing.pdf_issued_at') }} : {{ $note->issued_at->setTimezone(config('hl.timezone'))->format('d/m/Y') }}</div>
            <div>{{ __('billing.pdf_credit_note_invoice') }} : <strong>{{ $invoice?->number }}</strong>@if ($invoice?->issued_at) ({{ $invoice->issued_at->setTimezone(config('hl.timezone'))->format('d/m/Y') }})@endif</div>
            @if ($note->isReversal())<div>{{ __('billing.pdf_credit_note_reverses', ['number' => $note->reverses?->number]) }}</div>@endif
        </td>
    </tr>
    <tr>
        <td></td>
        <td>
            <div class="box">
                <div class="muted">{{ __('billing.pdf_billed_to') }}</div>
                <div style="font-weight:bold">{{ $partner['company_name'] ?? '' }}</div>
                <div>{{ $partner['legal_form'] ?? '' }}</div>
                <div>{{ $partner['billing_address'] ?? '' }}, {{ $partner['billing_city'] ?? '' }}</div>
                <div>ICE : {{ !empty($partner['ice_not_applicable']) ? __('billing.pdf_ice_na') : ($partner['ice'] ?? '') }}</div>
                @if (!empty($partner['tax_id']))<div>{{ __('billing.pdf_tax_id') }} : {{ $partner['tax_id'] }}</div>@endif
                @if (!empty($partner['registry_number']))<div>{{ __('billing.pdf_registry') }} : {{ $partner['registry_number'] }}</div>@endif
            </div>
        </td>
    </tr>
</table>

<div class="box" style="margin-bottom:12px"><span class="muted">{{ __('billing.credit_note_reason') }} :</span> {{ $note->reason }}</div>

@if ($note->lines->isNotEmpty())
<table class="lines">
    <thead>
        <tr>
            <th>{{ __('booking.reference') }}</th>
            <th>{{ __('billing.spa') }}</th>
            <th>{{ __('billing.pdf_description') }}</th>
            <th class="r">{{ __('billing.credit_note_credited') }}</th>
        </tr>
    </thead>
    <tbody>
    @foreach ($note->lines as $line)
        <tr>
            <td>{{ $line->booking_reference }}</td>
            <td>{{ $line->spa?->name ?? '—' }}</td>
            <td>{{ $line->description }}</td>
            <td class="r">{{ $fmt($line->amount) }}</td>
        </tr>
    @endforeach
    </tbody>
</table>
@endif

<table class="totals" style="width:45%;margin:14px 0 0 auto">
    <tr><td>{{ __('billing.subtotal_ht') }}</td><td class="r">{{ $fmt($note->subtotal_ht) }}</td></tr>
    @if ($note->vat_mode === 'ht_plus_vat')
        <tr><td>{{ __('billing.vat') }} ({{ $note->vat_rate }} %)</td><td class="r">{{ $fmt($note->vat_amount) }}</td></tr>
    @elseif ($note->vat_mode === 'ttc_included')
        <tr><td>{{ __('billing.vat_included') }} ({{ $note->vat_rate }} %)</td><td class="r">{{ $fmt($note->vat_amount) }}</td></tr>
    @else
        <tr><td colspan="2" class="muted">{{ __('billing.vat_none') }}</td></tr>
    @endif
    <tr class="grand"><td>{{ __('billing.credit_note_total') }}</td><td class="r">{{ $fmt($note->total_ttc) }}</td></tr>
</table>

<div class="box" style="margin-top:14px">
    <div>{{ __('billing.pdf_credit_note_balance', ['number' => $invoice?->number, 'balance' => $fmt($note->invoice_balance_after)]) }}</div>
    <div class="muted">{{ __('billing.pdf_credit_note_note') }}</div>
</div>

<div class="foot">{{ $issuer['company_name'] ?? 'HammamLuxury' }} · {{ $note->number }} · {{ __('billing.pdf_footer') }}</div>
</body>
</html>
