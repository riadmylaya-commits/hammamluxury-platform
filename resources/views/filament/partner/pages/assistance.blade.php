<x-filament-panels::page>
    @php($inp = 'width:100%;border:1px solid #d1d5db;border-radius:.5rem;padding:.5rem .75rem;font-size:.9rem;background:#fff;color:#111827')
    <x-filament::section>
        <form wire:submit="send" style="display:grid;gap:.9rem">
            <div>
                <label for="as-subject" class="text-sm font-medium">{{ __('support.subject') }}</label>
                <select id="as-subject" wire:model="subject" required style="{{ $inp }}">
                    <option value="">—</option>
                    @foreach (__('support.subjects') as $k => $v)<option value="{{ $k }}">{{ $v }}</option>@endforeach
                </select>
                @error('subject')<p class="text-sm text-danger-600">{{ $message }}</p>@enderror
            </div>
            <div>
                <label for="as-body" class="text-sm font-medium">{{ __('support.description') }}</label>
                <textarea id="as-body" wire:model="body" rows="5" required minlength="20" maxlength="3000" style="{{ $inp }}"></textarea>
                <p class="text-xs text-gray-500">{{ __('support.description_help') }}</p>
                @error('body')<p class="text-sm text-danger-600">{{ $message }}</p>@enderror
            </div>
            <div><x-filament::button type="submit" wire:loading.attr="disabled">{{ __('support.send') }}</x-filament::button></div>
        </form>
    </x-filament::section>

    <x-filament::section :heading="__('support.my_requests')">
        @php($requests = $this->requests())
        @forelse ($requests as $r)
            <div style="border-top:1px solid #e5e7eb;padding:.6rem 0">
                <button type="button" wire:click="select({{ $r->id }})" style="display:flex;gap:.5rem;align-items:center;flex-wrap:wrap;width:100%;text-align:left">
                    <x-filament::badge :color="\App\Models\SupportRequest::statusColor($r->status)">{{ __('support.statuses.'.$r->status) }}</x-filament::badge>
                    <b class="text-sm">{{ $r->subject }}</b>
                    <span class="text-xs text-gray-500">{{ $r->reference }} · {{ $r->created_at->format('d/m/Y H:i') }}</span>
                </button>
                @if ($selected === $r->id)
                    <div class="text-sm" style="margin-top:.5rem;display:grid;gap:.5rem">
                        <div style="background:#f9fafb;border-radius:.5rem;padding:.6rem;white-space:pre-line">{{ $r->body }}</div>
                        @forelse ($r->messages as $m)
                            <div style="border-left:3px solid {{ $m->sender === 'admin' ? '#b45309' : '#9ca3af' }};padding-left:.6rem">
                                <div class="text-xs text-gray-500">{{ __('support.senders.'.$m->sender) }} · {{ $m->created_at->format('d/m/Y H:i') }}</div>
                                <div style="white-space:pre-line">{{ $m->body }}</div>
                            </div>
                        @empty
                            <p class="text-xs text-gray-500">{{ __('support.no_reply') }}</p>
                        @endforelse
                        <form wire:submit="sendFollowUp" style="display:grid;gap:.4rem">
                            <label for="as-follow" class="text-xs font-medium">{{ __('support.follow_up') }}</label>
                            <textarea id="as-follow" wire:model="followUp" rows="2" maxlength="3000" style="{{ $inp }}"></textarea>
                            @error('followUp')<p class="text-sm text-danger-600">{{ $message }}</p>@enderror
                            <div><x-filament::button type="submit" size="sm" color="gray">{{ __('support.follow_up') }}</x-filament::button></div>
                        </form>
                    </div>
                @endif
            </div>
        @empty
            <p class="text-sm text-gray-500">{{ __('support.none') }}</p>
        @endforelse
    </x-filament::section>

    <x-filament::section :heading="__('support.faq_title')">
        @foreach (__('support.faq') as $item)
            <details style="border-top:1px solid #e5e7eb;padding:.6rem 0">
                <summary class="text-sm font-medium" style="cursor:pointer">{{ $item['q'] }}</summary>
                <p class="text-sm text-gray-600" style="margin-top:.4rem">{{ $item['a'] }}</p>
            </details>
        @endforeach
    </x-filament::section>
</x-filament-panels::page>
