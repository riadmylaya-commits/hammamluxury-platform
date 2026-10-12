<div class="card" style="margin:16px auto 32px;max-width:720px;padding:24px 20px">
@php($b = $review->booking)
<div class="xs muted" style="text-transform:uppercase;letter-spacing:.06em">{{ __('ui.review.verified_booking') }}</div>
<h1 style="font-size:24px;margin:4px 0 2px">{{ $review->spa->name }}</h1>
@if ($b)<p class="muted small" style="margin:0 0 14px">{{ $b->start_at->locale(app()->getLocale())->isoFormat('LL') }} · {{ $b->participants->pluck('treatment_name')->unique()->implode(', ') }}</p>@endif

@if ($done)
    <div class="notice ok" style="margin:12px 0"><b>{{ __('ui.review.thanks_title') }}</b><br>{{ __('ui.review.thanks_body') }}</div>
    <a class="btn sec" href="{{ route('spa.show', $review->spa->slug) }}">{{ __('ui.review.back_to_spa') }}</a>
@else
    <div class="rv-steps"><span @class(['on' => $step === 1])>1. {{ __('ui.review.step1') }}</span><span @class(['on' => $step === 2])>2. {{ __('ui.review.step2') }}</span></div>
    @if ($error)<div class="notice bad" style="margin:10px 0">{{ $error }}</div>@endif

    @if ($step === 1)
        <h2 style="font-size:18px;margin:16px 0 6px">{{ __('ui.review.overall') }}</h2>
        <div class="rv-stars" role="radiogroup" aria-label="{{ __('ui.review.overall') }}">
            @for ($i = 1; $i <= 5; $i++)
                <button type="button" wire:click="setRating({{ $i }})" @class(['on' => $i <= $rating]) role="radio" aria-checked="{{ $i === $rating ? 'true' : 'false' }}" aria-label="{{ $i }}">★</button>
            @endfor
            <span class="small muted" style="margin-left:8px">{{ $rating ? __('ui.review.rating_labels.'.$rating) : '' }}</span>
        </div>
        @error('rating')<span class="ferr">{{ $message }}</span>@enderror

        <h2 style="font-size:18px;margin:22px 0 4px">{{ __('ui.review.criteria_title') }}</h2>
        <p class="muted small" style="margin:0 0 10px">{{ __('ui.review.criteria_hint') }}</p>
        <div class="rv-crit">
            @foreach (\App\Models\Review::CRITERIA as $key)
                <div class="rv-row"><span>{{ __('ui.review.criteria.'.$key) }}</span>
                    <span class="rv-smileys">
                        @foreach ([1 => '🙁', 2 => '😐', 3 => '😊'] as $v => $face)
                            <button type="button" wire:click="setCriterion('{{ $key }}', {{ $v }})" @class(['on' => ($criteria[$key] ?? 0) === $v]) aria-label="{{ __('ui.review.smiley.'.$v) }}" title="{{ __('ui.review.smiley.'.$v) }}">{{ $face }}</button>
                        @endforeach
                    </span>
                </div>
            @endforeach
        </div>

        <h2 style="font-size:18px;margin:22px 0 4px">{{ __('ui.review.bonus_title') }} <span class="xs muted" style="font-weight:400">{{ __('ui.review.optional') }}</span></h2>
        <div class="rv-crit">
            @foreach (\App\Models\Review::BONUS as $key)
                <div class="rv-row"><span>{{ __('ui.review.bonus.'.$key) }}</span>
                    <span class="rv-yesno">
                        <button type="button" wire:click="setBonus('{{ $key }}', 'yes')" @class(['on' => ($bonus[$key] ?? '') === 'yes'])>{{ __('ui.review.yes') }}</button>
                        <button type="button" wire:click="setBonus('{{ $key }}', 'no')" @class(['on' => ($bonus[$key] ?? '') === 'no'])>{{ __('ui.review.no') }}</button>
                    </span>
                </div>
            @endforeach
        </div>
        <div class="row" style="margin-top:20px"><span class="sp"></span><button class="btn" type="button" wire:click="next">{{ __('ui.review.continue') }}</button></div>

    @else
        <div class="row small" style="margin:14px 0 6px"><span class="rating"><b>{{ $rating }}/5</b></span><span class="muted">{{ __('ui.review.rating_labels.'.$rating) }}</span><span class="sp"></span><button class="btn ghost sm" type="button" wire:click="back">{{ __('ui.review.edit_rating') }}</button></div>
        @if ($rating <= 3)<div class="notice info small" style="margin:8px 0 14px">{{ __('ui.review.low_rating_hint') }}</div>@endif
        <form wire:submit="submit" class="frm">
            <div class="fld"><label for="rv-liked">{{ __('ui.review.liked') }} <span class="muted" style="text-transform:none">{{ __('ui.review.optional') }}</span></label><textarea id="rv-liked" class="inp" rows="3" wire:model="liked" maxlength="1500" style="min-height:70px"></textarea>@error('liked')<span class="ferr">{{ $message }}</span>@enderror</div>
            <div class="fld"><label for="rv-improve">{{ __('ui.review.improve') }} <span class="muted" style="text-transform:none">{{ __('ui.review.optional') }}</span></label><textarea id="rv-improve" class="inp" rows="3" wire:model="improve" maxlength="1500" style="min-height:70px"></textarea>@error('improve')<span class="ferr">{{ $message }}</span>@enderror</div>
            <div class="fld"><label for="rv-title">{{ __('ui.review.title') }}</label><input id="rv-title" class="inp" wire:model="title" maxlength="150" placeholder="{{ __('ui.review.title_ph') }}">@error('title')<span class="ferr">{{ $message }}</span>@enderror</div>
            <div class="fld"><label for="rv-body">{{ __('ui.review.body') }}</label><textarea id="rv-body" class="inp" rows="6" wire:model.live.debounce.300ms="body" required minlength="20" maxlength="3000" placeholder="{{ __('ui.review.body_ph') }}"></textarea>
                <span class="xs muted">{{ __('ui.review.body_min', ['min' => \App\Models\Review::MIN_BODY, 'n' => mb_strlen($body)]) }}</span>
                @error('body')<span class="ferr">{{ $message }}</span>@enderror</div>
            <div class="fld"><label for="rv-photos">{{ __('ui.review.photos', ['max' => \App\Models\Review::MAX_PHOTOS]) }} <span class="muted" style="text-transform:none">{{ __('ui.review.optional') }}</span></label>
                <input id="rv-photos" type="file" class="inp" wire:model="photos" multiple accept="image/jpeg,image/png,image/webp" @disabled(count($photos) >= \App\Models\Review::MAX_PHOTOS)>
                <div wire:loading wire:target="photos" class="xs muted">{{ __('ui.review.uploading') }}</div>
                @error('photos')<span class="ferr">{{ $message }}</span>@enderror @error('photos.*')<span class="ferr">{{ $message }}</span>@enderror
                @if ($photos)<div class="rv-thumbs">@foreach ($photos as $i => $p)<span><img src="{{ $p->temporaryUrl() }}" alt=""><button type="button" wire:click="removePhoto({{ $i }})" aria-label="{{ __('ui.review.remove_photo') }}">×</button></span>@endforeach</div>@endif
                <div class="notice small" style="margin-top:6px"><b>{{ __('ui.review.charter_title') }}</b> {{ __('ui.review.charter') }}</div>
            </div>
            <p class="xs muted" style="margin:0">{{ __('ui.review.moderation_note') }}</p>
            <button class="btn full" type="submit" wire:loading.attr="disabled" wire:target="submit,photos">{{ __('ui.review.publish') }}</button>
        </form>
    @endif
@endif
</div>
