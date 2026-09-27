<div class="card prose" style="margin:16px auto 32px;max-width:720px;padding:24px 20px">
    <h1>{{ __('pages.contact.title') }}</h1>
    <p>{{ __('pages.contact.intro') }}</p>
    <div class="notice info small" style="margin:12px 0 18px">{{ __('pages.contact.booking_hint') }}</div>

    @if ($sent)
        <div class="notice ok" style="margin:12px 0"><b>{{ __('pages.contact.sent_title') }}</b><br>{{ __('pages.contact.sent_body') }}</div>
    @else
        <form wire:submit="send" class="frm">
            <div class="grid2">
                <div class="fld"><label for="c-name">{{ __('pages.contact.name') }}</label><input id="c-name" class="inp" wire:model="name" required maxlength="120" autocomplete="name">@error('name')<span class="ferr">{{ $message }}</span>@enderror</div>
                <div class="fld"><label for="c-email">{{ __('pages.contact.email') }}</label><input id="c-email" class="inp" type="email" wire:model="email" required maxlength="190" autocomplete="email">@error('email')<span class="ferr">{{ $message }}</span>@enderror</div>
            </div>
            <div class="fld"><label for="c-subject">{{ __('pages.contact.subject') }}</label>
                <select id="c-subject" class="inp" wire:model="subject" required><option value="">—</option>@foreach (__('pages.contact.subjects') as $k => $v)<option value="{{ $v }}">{{ $v }}</option>@endforeach</select>
                @error('subject')<span class="ferr">{{ $message }}</span>@enderror</div>
            <div class="fld"><label for="c-message">{{ __('pages.contact.message') }}</label><textarea id="c-message" class="inp" rows="6" wire:model="message" required minlength="20" maxlength="3000"></textarea>@error('message')<span class="ferr">{{ $message }}</span>@enderror</div>
            <div class="hp" aria-hidden="true"><input type="text" wire:model="hp_website" tabindex="-1" autocomplete="off"></div>
            <button class="btn" type="submit" wire:loading.attr="disabled">{{ __('pages.contact.send') }}</button>
        </form>
    @endif

    <p class="muted small" style="margin-top:22px">{{ __('pages.contact.partner_cta') }} <a href="{{ route('filament.partner.auth.register') }}">{{ __('pages.contact.partner_link') }}</a></p>
</div>
