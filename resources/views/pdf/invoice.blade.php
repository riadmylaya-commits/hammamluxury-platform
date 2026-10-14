@php
    $fmt = fn ($v) => number_format((float) $v, 2, ',', ' ').' '.$invoice->currency;
    $issuer = $invoice->issuer_snapshot ?? [];
    $partner = $invoice->partner_snapshot ?? [];
    $period = \Carbon\CarbonImmutable::createFromFormat('Y-m-d', $invoice->period.'-01')->locale(app()->getLocale());
@endphp
<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
<meta charset="utf-8">
<title>{{ $invoice->number }}</title>
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
    .section td { background: #f9fafb; font-weight: bold; }
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
            <div style="font-size:16px;font-weight:bold">{{ __('billing.pdf_invoice') }} {{ $invoice->number }}</div>
            <div>{{ __('billing.pdf_issued_at') }} : {{ $invoice->issued_at->setTimezone(config('hl.timezone'))->format('d/m/Y') }}</div>
            <div>{{ __('billing.pdf_due_at') }} : <strong>{{ $invoice->due_at->format('d/m/Y') }}</strong></div>
            <div>{{ __('billing.pdf_period') }} : {{ ucfirst($period->translatedFormat('F Y')) }}</div>
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

<table class="lines">
    <thead>
        <tr>
            <th>{{ __('booking.reference') }}</th>
            <th>{{ __('billing.service_date') }}</th>
            <th>{{ __('billing.pdf_description') }}</th>
            <th class="r">{{ __('billing.base') }}</th>
            <th class="r">{{ __('billing.rate') }}</th>
            <th class="r">{{ __('billing.commission') }}</th>
            <th>{{ __('billing.origin_period') }}</th>
        </tr>
    </thead>
    <tbody>
    @foreach ($sections as $section)
        <tr class="section"><td colspan="7">{{ $section['spa']?->name ?? '—' }}</td></tr>
        @foreach ($section['lines'] as $line)
            <tr>
                <td>{{ $line->booking_reference }}</td>
                <td>{{ $line->service_at?->setTimezone(config('hl.timezone'))->format('d/m/Y H:i') }}</td>
                <td>{{ $line->description }}</td>
                <td class="r">{{ $line->base_amount !== null ? $fmt($line->base_amount) : '' }}</td>
                <td class="r">{{ $line->commission_pct !== null ? rtrim(rtrim(number_format($line->commission_pct, 2, ',', ''), '0'), ',').' %' : '' }}</td>
                <td class="r">{{ $fmt($line->amount) }}</td>
                <td>{{ $line->origin_period }}@if ($line->carried) · {{ __('billing.carried') }}@endif</td>
            </tr>
        @endforeach
        <tr class="section"><td colspan="5" class="r">{{ __('billing.subtotal_spa') }}</td><td class="r">{{ $fmt($section['total']) }}</td><td></td></tr>
    @endforeach
    </tbody>
</table>

<table class="totals" style="width:45%;margin:14px 0 0 auto">
    <tr><td>{{ __('billing.subtotal_ht') }}</td><td class="r">{{ $fmt($invoice->subtotal_ht) }}</td></tr>
    @if ($invoice->vat_mode === 'ht_plus_vat')
        <tr><td>{{ __('billing.vat') }} ({{ $invoice->vat_rate }} %)</td><td class="r">{{ $fmt($invoice->vat_amount) }}</td></tr>
    @elseif ($invoice->vat_mode === 'ttc_included')
        <tr><td>{{ __('billing.vat_included') }} ({{ $invoice->vat_rate }} %)</td><td class="r">{{ $fmt($invoice->vat_amount) }}</td></tr>
    @else
        <tr><td colspan="2" class="muted">{{ __('billing.vat_none') }}</td></tr>
    @endif
    <tr class="grand"><td>{{ __('billing.total_ttc') }}</td><td class="r">{{ $fmt($invoice->total_ttc) }}</td></tr>
</table>

<div class="box" style="margin-top:14px">
    <div style="font-weight:bold">{{ __('billing.pdf_payment') }}</div>
    <div>{{ __('billing.pdf_payment_terms', ['days' => (int) $invoice->issued_at->startOfDay()->diffInDays($invoice->due_at->startOfDay()), 'date' => $invoice->due_at->format('d/m/Y')]) }}</div>
    @if (!empty($issuer['bank_account']))<div>{{ __('billing.pdf_bank') }} : {{ $issuer['bank_account'] }}</div>@endif
    <div class="muted">{{ __('billing.pdf_cash_note') }}</div>
</div>

<div class="foot">{{ $issuer['company_name'] ?? 'HammamLuxury' }} · {{ $invoice->number }} · {{ __('billing.pdf_footer') }}</div>
</body>
</html>
