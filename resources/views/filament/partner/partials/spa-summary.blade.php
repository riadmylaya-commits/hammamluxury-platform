@php($cur = config('hl.currency'))
<div class="space-y-4 text-sm">
    <div class="grid gap-4 sm:grid-cols-2">
        <div class="rounded-lg border p-3 dark:border-gray-700">
            <div class="font-semibold">{{ __('partner.step_account') }}</div>
            <div>{{ $user->name }} · {{ $user->partner?->company_name }}</div>
            <div class="text-gray-500">{{ $user->email }} · {{ $user->phone }}@if ($user->whatsapp && $user->whatsapp !== $user->phone) · WhatsApp {{ $user->whatsapp }}@endif</div>
        </div>
        <div class="rounded-lg border p-3 dark:border-gray-700">
            <div class="font-semibold">{{ $spa->name }}</div>
            <div>{{ $spa->cityRef?->label() ?? $spa->city }}@if ($spa->area) · {{ $spa->area }}@endif · {{ \App\Models\Category::labelsBySlug()[$spa->category] ?? $spa->category }}</div>
            <div class="text-gray-500">{{ $spa->address }} · {{ $spa->phone }}@if ($spa->whatsapp && $spa->whatsapp !== $spa->phone) · WhatsApp {{ $spa->whatsapp }}@endif</div>
        </div>
    </div>

    @if ($spa->photos->isNotEmpty())
        <div class="grid grid-cols-5 gap-2 sm:grid-cols-10">
            @foreach ($spa->photos->sortBy('sort_order') as $p)
                <img src="{{ $p->url() }}" alt="" class="aspect-[4/3] w-full rounded object-cover" loading="lazy">
            @endforeach
        </div>
        <div class="text-gray-500">{{ trans_choice('partner.photos_count', $spa->photos->count(), ['n' => $spa->photos->count()]) }}</div>
    @endif

    <div class="grid gap-4 sm:grid-cols-2">
        <div>
            <div class="font-semibold">{{ __('partner.experiences') }}</div>
            <div>{{ $spa->categories->map->label()->implode(', ') ?: '—' }}</div>
            <div class="mt-2 font-semibold">{{ __('partner.amenities') }}</div>
            <div>{{ $spa->amenities->map->label()->implode(', ') ?: '—' }}</div>
        </div>
        <div>
            <div class="font-semibold">{{ __('partner.step_hours') }}</div>
            @foreach ($spa->hours->sortBy(['weekday', 'opens_min']) as $h)
                <div>{{ $weekdays[$h->weekday] ?? $h->weekday }} : {{ \App\Models\SpaHour::toHhmm($h->opens_min) }}–{{ \App\Models\SpaHour::toHhmm($h->closes_min) }}</div>
            @endforeach
            <div class="mt-2 text-gray-500">
                @if ($capacity['hammam_capacity']) {{ __('partner.hammam_capacity') }} : {{ $capacity['hammam_capacity'] }} · @endif
                @if ($capacity['massage_cabins']) {{ __('partner.massage_cabins') }} : {{ $capacity['massage_cabins'] }} · @endif
                @if ($capacity['treatment_rooms']) {{ __('partner.treatment_rooms') }} : {{ $capacity['treatment_rooms'] }} @endif
            </div>
        </div>
    </div>

    <div>
        <div class="font-semibold">{{ __('partner.step_treatments') }}</div>
        <table class="w-full text-left">
            @foreach ($spa->treatments->sortBy('sort_order') as $t)
                <tr class="border-t dark:border-gray-700">
                    <td class="py-1">{{ $t->name_fr }} <span class="text-gray-500">({{ __('ui.cat')[$t->category] ?? $t->category }})</span></td>
                    <td class="py-1">{{ $t->duration_min }} min</td>
                    <td class="py-1 text-right">{{ number_format((float) $t->price_solo, 0, ',', ' ') }} {{ $cur }}@if ($t->price_couple) · {{ __('partner.price_couple') }} {{ number_format((float) $t->price_couple, 0, ',', ' ') }} {{ $cur }}@endif</td>
                </tr>
            @endforeach
        </table>
    </div>

    @if ($missing)
        <div class="rounded-lg border border-danger-300 bg-danger-50 p-3 text-danger-800">
            <div class="font-semibold">{{ __('partner.submit_blocked') }}</div>
            <ul class="list-disc pl-5">@foreach ($missing as $m)<li>{{ $m }}</li>@endforeach</ul>
        </div>
    @else
        <div class="rounded-lg border border-success-300 bg-success-50 p-3 text-success-800">{{ __('partner.summary_ready') }}</div>
    @endif
    <p class="text-gray-500">{{ __('partner.summary_admin_note') }}</p>
</div>
