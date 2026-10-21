@php
    $fmt = fn ($v) => number_format((float) $v, 2, ',', ' ').' '.$currency;
    $na = fn ($v) => filled($v) ? $v : __('billing.specimen_to_complete');
    $pct = rtrim(rtrim(number_format($rate, 2, ',', ''), '0'), ',');
@endphp
<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
<meta charset="utf-8">
<title>{{ $number }}</title>
<style>
    @page { margin: 22mm 16mm 20mm; }
    body { font-family: 'DejaVu Sans', sans-serif; font-size: 10.5px; color: #1f2937; margin: 0; }
    .wm { position: fixed; top: 42%; left: 0; right: 0; text-align: center; font-size: 78px; font-weight: bold; color: rgba(124, 45, 18, .08); transform: rotate(-28deg); letter-spacing: 6px; }
    .banner { border: 2px solid #b91c1c; color: #b91c1c; font-weight: bold; text-align: center; padding: 6px; font-size: 12px; margin-bottom: 14px; letter-spacing: .5px; }
    .brand { font-size: 26px; font-weight: bold; color: #7c2d12; letter-spacing: 1px; margin: 0; }
    .brand span { color: #b8743a; }
    .muted { color: #6b7280; }
    table { width: 100%; border-collapse: collapse; }
    .head td { vertical-align: top; width: 50%; padding: 0 0 12px; }
    .box { border: 1px solid #e5e7eb; padding: 9px 11px; }
    .title { font-size: 17px; font-weight: bold; margin: 0 0 6px; }
    .meta td { padding: 2px 0; }
    .lines th { background: #fdf2e9; text-align: left; padding: 6px 8px; border-bottom: 1px solid #e5e7eb; font-size: 9.5px; }
    .lines td { padding: 7px 8px; border-bottom: 1px solid #f3f4f6; }
    .r { text-align: right; white-space: nowrap; }
    .lines th.r, .lines td.r { white-space: nowrap; }
    .totals td { padding: 4px 8px; }
    .totals .grand td { font-weight: bold; font-size: 12.5px; border-top: 2px solid #7c2d12; padding-top: 7px; }
    .foot { position: fixed; bottom: 0; left: 0; right: 0; font-size: 8.5px; color: #6b7280; border-top: 1px solid #e5e7eb; padding-top: 4px; }
</style>
</head>
<body>
<div class="wm">{{ __('billing.specimen_watermark') }}</div>
<div class="banner">{{ __('billing.specimen_banner') }}</div>

<table class="head">
    <tr>
        <td>
            <p class="brand">Hammam<span>Luxury</span></p>
            <div style="font-weight:bold;margin-top:6px">{{ $na($issuer['company_name'] ?? null) }}</div>
            <div>{{ $na($issuer['legal_form'] ?? null) }}</div>
            <div>{!! nl2br(e($na($issuer['address'] ?? null))) !!}</div>
            <div>ICE : {{ $na($issuer['ice'] ?? null) }}</div>
            <div>{{ __('billing.pdf_tax_id') }} : {{ $na($issuer['tax_id'] ?? null) }}</div>
            <div>{{ __('billing.pdf_registry') }} : {{ $na($issuer['registry_number'] ?? null) }}</div>
        </td>
        <td class="r">
            <div class="title">{{ __('billing.specimen_title') }}</div>
            <table class="meta" style="width:auto;margin-left:auto">
                <tr><td class="muted" style="padding-right:10px">{{ __('billing.pdf_invoice_number') }}</td><td class="r"><strong>{{ $number }}</strong></td></tr>
                <tr><td class="muted" style="padding-right:10px">{{ __('billing.pdf_issued_at') }}</td><td class="r">{{ $issued_at->format('d/m/Y') }}</td></tr>
                <tr><td class="muted" style="padding-right:10px">{{ __('billing.pdf_period') }}</td><td class="r">{{ ucfirst($period->locale(app()->getLocale())->translatedFormat('F Y')) }}</td></tr>
                <tr><td class="muted" style="padding-right:10px">{{ __('billing.pdf_due_at') }}</td><td class="r">{{ __('billing.specimen_to_define') }}</td></tr>
            </table>
        </td>
    </tr>
    <tr>
        <td></td>
        <td>
            <div class="box">
                <div class="muted">{{ __('billing.pdf_billed_to') }}</div>
                <div style="font-weight:bold">{{ $partner['company_name'] }}</div>
                <div>{{ $partner['legal_form'] }} · {{ $partner['spa_name'] }}</div>
                <div>{{ $partner['billing_address'] }}, {{ $partner['billing_city'] }}</div>
                <div>ICE : {{ $partner['ice'] }} · {{ __('billing.pdf_tax_id') }} : {{ $partner['tax_id'] }} · {{ __('billing.pdf_registry') }} : {{ $partner['registry_number'] }}</div>
            </div>
        </td>
    </tr>
</table>

<table class="lines">
    <thead>
        <tr>
            <th>{{ __('billing.pdf_description') }}</th>
            <th class="r">{{ __('billing.specimen_bookings') }}</th>
            <th class="r" style="white-space:normal;max-width:150px">{{ __('billing.specimen_base') }}</th>
            <th class="r">{{ __('billing.rate') }}</th>
            <th class="r">{{ __('billing.commission') }}</th>
        </tr>
    </thead>
    <tbody>
        <tr>
            <td>{{ __('billing.specimen_line', ['period' => ucfirst($period->locale(app()->getLocale())->translatedFormat('F Y'))]) }}</td>
            <td class="r">{{ $bookings_count }}</td>
            <td class="r">{{ $fmt($base) }}</td>
            <td class="r">{{ $pct }} %</td>
            <td class="r">{{ $fmt($commission) }}</td>
        </tr>
    </tbody>
</table>

<table class="totals" style="width:52%;margin:16px 0 0 auto">
    <tr><td>{{ __('billing.specimen_base') }}</td><td class="r">{{ $fmt($base) }}</td></tr>
    <tr><td>{{ __('billing.specimen_commission', ['pct' => $pct]) }}</td><td class="r">{{ $fmt($commission) }}</td></tr>
    <tr class="grand"><td>{{ __('billing.specimen_total_due') }}</td><td class="r">{{ $fmt($commission) }}</td></tr>
</table>

<div class="box" style="margin-top:18px">
    <div style="font-weight:bold">{{ __('billing.pdf_payment') }}</div>
    <div class="muted">{{ __('billing.pdf_due_at') }} : {{ __('billing.specimen_to_define') }}</div>
    <div style="height:34px"></div>
</div>

<p class="muted" style="margin-top:14px;font-size:9px">{{ __('billing.specimen_note') }}</p>

<div class="foot">HammamLuxury · {{ $number }} · {{ __('billing.specimen_banner') }}</div>
</body>
</html>
