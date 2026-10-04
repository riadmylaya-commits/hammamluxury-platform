<x-filament-panels::page>
    @php
        $g = $this->grid();
        $list = $this->dayBookings();
        $statuses = __('ui.status');
        $today = \Carbon\CarbonImmutable::today()->toDateString();
        $locale = app()->getLocale();
        $monthLabel = ucfirst($g['first']->locale($locale)->isoFormat('MMMM YYYY'));
        $dayLabel = ucfirst(\Carbon\CarbonImmutable::parse($this->day)->locale($locale)->isoFormat('dddd D MMMM YYYY'));
        $weekdays = collect(range(0, 6))->map(fn ($i) => ucfirst(\Carbon\CarbonImmutable::parse('2024-01-01')->addDays($i)->locale($locale)->isoFormat('ddd')));
    @endphp

    <style>
        .hl-cal{background:#fff;border:1px solid #e5e7eb;border-radius:.9rem;padding:1rem;max-width:40rem}
        .dark .hl-cal{background:#111827;border-color:#374151}
        .hl-cal-head{display:flex;align-items:center;justify-content:space-between;margin-bottom:.75rem;font-weight:700}
        .hl-cal-head button{padding:.25rem .75rem;border-radius:.5rem;font-size:1.1rem;line-height:1}
        .hl-cal-head button:hover{background:#f3f4f6}.dark .hl-cal-head button:hover{background:#1f2937}
        .hl-cal-grid{display:grid;grid-template-columns:repeat(7,1fr);gap:.15rem;text-align:center}
        .hl-cal-grid .wd{font-size:.72rem;color:#6b7280;padding-bottom:.35rem}
        .hl-day{position:relative;display:flex;flex-direction:column;align-items:center;gap:.2rem;padding:.45rem 0 .55rem;border-radius:.6rem;font-size:.95rem;border:0;background:none;cursor:pointer;color:inherit}
        .hl-day:hover{background:#f3f4f6}.dark .hl-day:hover{background:#1f2937}
        .hl-day.out{color:#9ca3af}.dark .hl-day.out{color:#4b5563}
        .hl-day.sel{background:#1f4d3f;color:#fff}.hl-day.sel .hl-dot{background:#fff}
        .hl-day.today:not(.sel){font-weight:700;text-decoration:underline;text-underline-offset:3px}
        .hl-dot{width:.4rem;height:.4rem;border-radius:999px;background:transparent}
        .hl-dot.blue{background:#1d4ed8}.hl-dot.gray{background:#9ca3af}
        .hl-legend{display:flex;gap:1rem;flex-wrap:wrap;font-size:.78rem;color:#6b7280;margin-top:.75rem}
        .hl-legend span{display:inline-flex;align-items:center;gap:.35rem}
        .hl-legend .hl-dot{display:inline-block}
        .hl-daylist{max-width:40rem;margin-top:1.25rem}
        .hl-daylist h2{font-size:1rem;font-weight:700;margin-bottom:.6rem}
        .hl-bk{display:flex;align-items:center;justify-content:space-between;gap:.75rem;background:#fff;border:1px solid #e5e7eb;border-radius:.9rem;padding:.8rem 1rem;margin-bottom:.5rem;color:inherit}
        .dark .hl-bk{background:#111827;border-color:#374151}
        .hl-bk:hover{background:#f9fafb}.dark .hl-bk:hover{background:#1f2937}
        .hl-bk .n{font-weight:700}.hl-bk .m{font-size:.85rem;color:#6b7280}
        .hl-badge{display:inline-block;border-radius:999px;padding:.15rem .65rem;font-size:.75rem;font-weight:600;white-space:nowrap}
        .hl-badge-success{background:#dcfce7;color:#166534}.hl-badge-warning{background:#fef3c7;color:#92400e}
        .hl-badge-danger{background:#fee2e2;color:#991b1b}.hl-badge-info{background:#dbeafe;color:#1e40af}.hl-badge-gray{background:#e5e7eb;color:#374151}
        .hl-empty{color:#6b7280;font-size:.9rem}
    </style>

    <div class="hl-cal">
        <div class="hl-cal-head">
            <button type="button" wire:click="previousMonth" aria-label="‹">‹</button>
            <span>{{ $monthLabel }}</span>
            <button type="button" wire:click="nextMonth" aria-label="›">›</button>
        </div>
        <div class="hl-cal-grid">
            @foreach ($weekdays as $wd)<div class="wd">{{ $wd }}</div>@endforeach
            @foreach ($g['weeks'] as $week)
                @foreach ($week as $d)
                    @php($key = $d->toDateString())
                    <button type="button" wire:click="selectDay('{{ $key }}')"
                        class="hl-day {{ $d->month !== $g['first']->month ? 'out' : '' }} {{ $key === $this->day ? 'sel' : '' }} {{ $key === $today ? 'today' : '' }}">
                        <span>{{ $d->day }}</span>
                        <span class="hl-dot {{ $g['dots'][$key] ?? '' }}"></span>
                    </button>
                @endforeach
            @endforeach
        </div>
        <div class="hl-legend">
            <span><i class="hl-dot blue"></i>{{ __('partner.cal_legend_blue') }}</span>
            <span><i class="hl-dot gray"></i>{{ __('partner.cal_legend_gray') }}</span>
            <span>{{ __('partner.cal_legend_none') }}</span>
        </div>
    </div>

    <div class="hl-daylist">
        <h2>{{ $dayLabel }}</h2>
        @forelse ($list as $b)
            <a class="hl-bk" href="{{ \App\Filament\Partner\Resources\BookingResource::getUrl('view', ['record' => $b]) }}">
                <div>
                    <div class="n">{{ $b->start_at->format('H:i') }} → {{ $b->end_at->format('H:i') }} · {{ $b->customerName() }}</div>
                    <div class="m">{{ $b->participants->groupBy('treatment_name')->map(fn ($grp, $n) => $grp->sum('party') > 1 ? "$n ×".$grp->sum('party') : $n)->implode(', ') }} · {{ $b->party }} {{ __('partner.pers') }} · {{ \App\Filament\Partner\Resources\BookingResource\Pages\ViewBooking::money((float) $b->total) }}</div>
                </div>
                <span class="hl-badge hl-badge-{{ \App\Filament\Shared\BookingActions::statusColor($b->status) }}">{{ $statuses[$b->status] ?? $b->status }}</span>
            </a>
        @empty
            <p class="hl-empty">{{ __('partner.cal_no_bookings') }}</p>
        @endforelse
    </div>
</x-filament-panels::page>
