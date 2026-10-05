<x-filament-panels::page>
    @php
        $b = $this->record;
        $s = $this->sheet();
        $money = fn ($v) => \App\Filament\Partner\Resources\BookingResource\Pages\ViewBooking::money((float) $v);
        $dur = fn ($m) => \App\Filament\Partner\Resources\BookingResource\Pages\ViewBooking::humanDuration((int) $m);
        $statuses = __('ui.status');
        $payments = __('partner.payment_statuses');
    @endphp

    <style>
        .hl-sheet{display:grid;gap:1rem;max-width:56rem}
        @media(min-width:900px){.hl-sheet{grid-template-columns:1fr 1fr}.hl-span{grid-column:1/-1}}
        .hl-card{background:#fff;border:1px solid #e5e7eb;border-radius:.9rem;padding:1rem 1.1rem}
        .dark .hl-card{background:#111827;border-color:#374151}
        .hl-card h3{font-size:.8rem;text-transform:uppercase;letter-spacing:.06em;color:#6b7280;margin:0 0 .6rem;font-weight:600}
        .hl-head{display:flex;flex-wrap:wrap;align-items:center;justify-content:space-between;gap:.75rem}
        .hl-head .hl-name{font-size:1.35rem;font-weight:700;line-height:1.2}
        .hl-head .hl-ref{font-family:ui-monospace,monospace;color:#6b7280;font-size:.9rem}
        .hl-badge{display:inline-block;border-radius:999px;padding:.15rem .65rem;font-size:.75rem;font-weight:600}
        .hl-badge-success{background:#dcfce7;color:#166534}.hl-badge-warning{background:#fef3c7;color:#92400e}
        .hl-badge-danger{background:#fee2e2;color:#991b1b}.hl-badge-info{background:#dbeafe;color:#1e40af}.hl-badge-gray{background:#e5e7eb;color:#374151}
        .hl-actions{display:grid;grid-template-columns:repeat(3,1fr);gap:.5rem;margin-top:1rem}
        .hl-btn{display:flex;flex-direction:column;align-items:center;gap:.25rem;padding:.7rem .4rem;border-radius:.75rem;border:1px solid #e5e7eb;background:#f9fafb;color:#111827;font-size:.8rem;font-weight:600;text-align:center;cursor:pointer}
        .hl-btn:hover{background:#f3f4f6}.hl-btn[disabled]{opacity:.45;cursor:not-allowed}
        .hl-btn svg{width:1.4rem;height:1.4rem}
        .dark .hl-btn{background:#1f2937;border-color:#374151;color:#f9fafb}
        .hl-big{font-size:1.15rem;font-weight:700}
        .hl-row{display:flex;justify-content:space-between;gap:1rem;padding:.35rem 0;border-bottom:1px dashed #e5e7eb;font-size:.95rem}
        .hl-row:last-child{border-bottom:0}
        .hl-row .hl-k{color:#6b7280}.hl-row .hl-v{text-align:right;font-weight:500}
        .hl-muted{color:#6b7280;font-size:.85rem}
        .hl-formula{font-weight:700;font-size:1.05rem}
        .hl-steps{margin:.35rem 0 0;padding:0;list-style:none}
        .hl-steps li{display:flex;justify-content:space-between;padding:.2rem 0;font-size:.95rem}
        .hl-chips{display:flex;flex-wrap:wrap;gap:.35rem;margin-top:.35rem}
        .hl-chip{background:#f3f4f6;border-radius:999px;padding:.15rem .6rem;font-size:.78rem}
        .dark .hl-chip{background:#374151}
        .hl-extra{display:flex;justify-content:space-between;gap:.5rem;padding:.3rem 0;font-size:.95rem;border-top:1px dashed #e5e7eb}
        .hl-total{display:flex;justify-content:space-between;font-weight:700;font-size:1.1rem;padding-top:.5rem;border-top:2px solid #111827;margin-top:.4rem}
        .dark .hl-total{border-color:#f9fafb}
        .hl-net{color:#166534}.hl-minus{color:#991b1b}
        .hl-note{border-left:3px solid #d1d5db;padding:.4rem .75rem;margin-bottom:.6rem}
        .hl-note .hl-meta{font-size:.75rem;color:#6b7280;display:flex;justify-content:space-between;gap:.5rem}
        .hl-note p{margin:.15rem 0 0;white-space:pre-line}
        .hl-status-actions{display:flex;flex-wrap:wrap;gap:.5rem}
        .hl-tel{display:inline-flex;align-items:center;gap:.5rem;font-size:1.25rem;font-weight:700;text-decoration:none;color:#1d4ed8}
        .hl-panel{display:none;margin-top:1rem;border-top:1px solid #e5e7eb;padding-top:.75rem}
        .hl-panel.open{display:block}
        .hl-count{position:absolute;top:.3rem;right:.4rem;background:#c4753b;color:#fff;border-radius:999px;font-size:.65rem;padding:.05rem .4rem}
        .hl-thread{display:flex;flex-direction:column;gap:.5rem;max-height:22rem;overflow:auto;padding:.2rem 0}
        .hl-msg{max-width:85%;border-radius:.8rem;padding:.5rem .75rem;background:#f3f4f6}
        .hl-msg-partner,.hl-msg-admin{align-self:flex-end;background:#fdf1e7}
        .dark .hl-msg{background:#1f2937}.dark .hl-msg-partner{background:#4a2f1c}
        .hl-msg .hl-meta{font-size:.72rem;color:#6b7280}
        .hl-msg p{margin:.15rem 0 0;white-space:pre-line;font-size:.95rem}
        .hl-compose{display:flex;flex-direction:column;gap:.5rem;align-items:flex-end;margin-top:.75rem}
        .hl-req{border-left:3px solid #f59e0b;padding:.5rem .75rem;margin-bottom:.5rem;background:#fffbeb;border-radius:0 .6rem .6rem 0}
        .dark .hl-req{background:#3b2a12}
        .hl-req p{margin:.2rem 0 0;white-space:pre-line}
    </style>

    <div class="hl-sheet" x-data="{ panel: null }" x-init="if (location.hash === '#messages') panel = 'message'">
        {{-- En-tête : client, n°, statut, 3 actions --}}
        <div class="hl-card hl-span">
            <div class="hl-head">
                <div>
                    <div class="hl-name">{{ $b->customerName() }}</div>
                    <div class="hl-ref">{{ __('partner.reference') }} {{ $b->reference }} · {{ $b->party }} {{ __('partner.pers') }}</div>
                </div>
                <div style="display:flex;gap:.4rem;flex-wrap:wrap">
                    <span class="hl-badge hl-badge-{{ \App\Filament\Shared\BookingActions::statusColor($b->status) }}">{{ $statuses[$b->status] ?? $b->status }}</span>
                    @if ($b->source === 'partner')<span class="hl-badge hl-badge-info">{{ __('partner.sources.partner') }}@if($b->channel) · {{ __('partner.channels.'.$b->channel) }}@endif</span>@endif
                    @if ($b->nothingDue())<span class="hl-badge hl-badge-gray">{{ __('partner.nothing_due_short') }}</span>@else<span class="hl-badge hl-badge-{{ \App\Filament\Shared\BookingActions::paymentColor($b->payment_status) }}">{{ $payments[$b->payment_status] ?? $b->payment_status }}</span>@endif
                </div>
            </div>

            @if ($b->decline)
                <div class="hl-panel open" style="border-left:3px solid #991b1b">
                    <h3>{{ __('partner.declined_block') }}</h3>
                    <div class="hl-row"><span class="hl-k">{{ __('partner.decline_reason') }}</span><span class="hl-v">{{ __('partner.decline_reasons')[$b->decline->reason] ?? $b->decline->reason }}</span></div>
                    <div class="hl-row"><span class="hl-k">{{ __('partner.declined_by') }}</span><span class="hl-v">{{ $b->decline->user?->name ?? (__('admin.decline_actors')[$b->decline->actor] ?? $b->decline->actor) }} · {{ $b->decline->created_at->format('d/m/Y H:i') }}</span></div>
                    @if ($b->decline->note)
                        <div class="hl-row"><span class="hl-k">{{ __('partner.decline_note') }}</span><span class="hl-v" style="white-space:pre-line">{{ $b->decline->note }}</span></div>
                    @endif
                </div>
            @endif

            <div class="hl-actions">
                <button type="button" class="hl-btn" @click="panel = panel === 'client' ? null : 'client'">
                    <x-heroicon-o-user />{{ __('partner.customer_details') }}
                </button>
                <button type="button" class="hl-btn" @click="panel = panel === 'contact' ? null : 'contact'">
                    <x-heroicon-o-phone />{{ __('partner.contact') }}
                </button>
                <button type="button" class="hl-btn" style="position:relative" @click="panel = panel === 'message' ? null : 'message'">
                    <x-heroicon-o-chat-bubble-left-right />{{ __('partner.message') }}
                    @if ($b->messages->isNotEmpty())<span class="hl-count">{{ $b->messages->count() }}</span>@endif
                </button>
            </div>

            <div class="hl-panel" :class="{ open: panel === 'client' }">
                <div class="hl-row"><span class="hl-k">{{ __('partner.name') }}</span><span class="hl-v">{{ $b->customerName() }}</span></div>
                <div class="hl-row"><span class="hl-k">{{ __('partner.party') }}</span><span class="hl-v">{{ $b->party }} {{ __('partner.pers') }}</span></div>
                <div class="hl-row"><span class="hl-k">{{ __('partner.preferred_language') }}</span><span class="hl-v">{{ $s['language'] }}</span></div>
                @if ($s['dial'])
                    <div class="hl-row"><span class="hl-k">{{ __('partner.dial_code') }}</span><span class="hl-v">{{ $s['dial'] }}</span></div>
                @endif
                @if ($b->hotel)
                    <div class="hl-row"><span class="hl-k">{{ __('partner.hotel') }}</span><span class="hl-v">{{ $b->hotel }}</span></div>
                @endif
                @if ($b->note)
                    <div class="hl-row"><span class="hl-k">{{ __('partner.customer_note') }}</span><span class="hl-v" style="white-space:pre-line">{{ $b->note }}</span></div>
                @endif
            </div>

            <div class="hl-panel" :class="{ open: panel === 'message' }" id="messages">
                <p class="hl-muted" style="margin-bottom:.6rem">{{ __('partner.messages_help') }}</p>
                <div class="hl-thread">
                    @forelse ($b->messages as $m)
                        <div class="hl-msg hl-msg-{{ $m->sender }}" wire:key="msg-{{ $m->id }}">
                            <div class="hl-meta">{{ __('partner.senders.'.$m->sender) }} · {{ $m->created_at->format('d/m/Y H:i') }}</div>
                            <p>{{ $m->body }}</p>
                        </div>
                    @empty
                        <p class="hl-muted">{{ __('partner.no_messages') }}</p>
                    @endforelse
                </div>
                @if ($b->isActive() || $b->status === 'completed')
                    <form wire:submit="sendMessage" class="hl-compose">
                        <textarea wire:model="messageBody" rows="3" maxlength="2000" class="fi-input" style="width:100%;border:1px solid #d1d5db;border-radius:.6rem;padding:.6rem" placeholder="{{ __('partner.message_placeholder') }}" required></textarea>
                        <x-filament::button type="submit" size="sm" icon="heroicon-o-paper-airplane" wire:loading.attr="disabled">{{ __('partner.send_message') }}</x-filament::button>
                    </form>
                @endif
            </div>

            <div class="hl-panel" :class="{ open: panel === 'contact' }">
                @if (! $s['contact_visible'])
                    <span class="hl-big">🔒 {{ __('partner.contact_hidden_until_confirmed') }}</span>
                    <p class="hl-muted" style="margin-top:.35rem">{{ __('partner.contact_hidden_help') }}</p>
                @elseif ($s['tel'])
                    <a class="hl-tel" href="{{ $s['tel'] }}"><x-heroicon-o-phone style="width:1.3rem;height:1.3rem" />{{ $s['phone'] }}</a>
                    <p class="hl-muted" style="margin-top:.35rem">{{ __('partner.contact_help') }}
                        @if ($s['whatsapp']) · <a href="{{ $s['whatsapp'] }}" target="_blank" rel="noopener" style="text-decoration:underline">WhatsApp</a> @endif
                    </p>
                    <p class="hl-muted" style="margin-top:.35rem">{{ __('partner.contact_platform_reminder') }}</p>
                @else
                    <span class="hl-big">{{ $s['phone'] ?: '—' }}</span>
                @endif
            </div>
        </div>

        {{-- Rendez-vous --}}
        <div class="hl-card">
            <h3>{{ __('partner.appointment') }}</h3>
            <div class="hl-big">{{ $b->start_at->translatedFormat('j F Y') }} — {{ $b->start_at->format('H\hi') }}</div>
            <div class="hl-row"><span class="hl-k">{{ __('partner.duration') }}</span><span class="hl-v">{{ $s['duration'] }} <span class="hl-muted">(→ {{ $b->end_at->format('H\hi') }})</span></span></div>
            <div class="hl-row"><span class="hl-k">{{ __('partner.party') }}</span><span class="hl-v">{{ $b->party }} {{ __('partner.pers') }}</span></div>
            <div class="hl-row"><span class="hl-k">{{ __('partner.establishment') }}</span><span class="hl-v">{{ $b->spa->name }}</span></div>
            @if ($b->isWaiting() && $b->expires_at)
                <div class="hl-row"><span class="hl-k">{{ __('partner.expires_at') }}</span><span class="hl-v">{{ $b->expires_at->diffForHumans() }}</span></div>
            @endif
            @if (in_array($b->status, ['confirmed', 'completed'], true) && $b->partnerWindowClosed())
                <p class="hl-muted" style="margin-top:.5rem">{{ __('partner.no_show_window_closed', ['until' => $s['no_show_until']->translatedFormat('j F') .' '. $s['no_show_until']->format('H\hi')]) }}</p>
            @elseif ($b->isConfirmed())
                <p class="hl-muted" style="margin-top:.5rem">{{ __('partner.no_show_window_hint', ['until' => $s['no_show_until']->translatedFormat('j F') .' '. $s['no_show_until']->format('H\hi')]) }}</p>
            @endif
            @if ($b->status === 'no_show')
                <div class="hl-row"><span class="hl-k">{{ __('partner.no_show_fee') }}</span><span class="hl-v">{{ $b->no_show_fee_waived ? __('partner.no_show_fee_waived') : $money($b->no_show_fee) }}</span></div>
            @endif
        </div>

        {{-- Prix / commission / net --}}
        <div class="hl-card">
            <h3>{{ __('partner.pricing') }}</h3>
            <div class="hl-row"><span class="hl-k">{{ __('partner.customer_total') }}</span><span class="hl-v">{{ $money($b->total) }}</span></div>
            @if ($b->source === 'partner')
                <p class="hl-muted" style="margin-top:.4rem">{{ __('partner.offline_no_commission') }}</p>
            @else
            <div class="hl-row"><span class="hl-k">{{ __('partner.commissionable') }}</span><span class="hl-v">{{ $money($s['commissionable']) }}</span></div>
            <div class="hl-row"><span class="hl-k">{{ __('partner.commission_hl', ['pct' => rtrim(rtrim(number_format($b->commission_pct, 2, ',', ''), '0'), ',')]) }}</span><span class="hl-v hl-minus">− {{ $money($b->commissionDue()) }}</span></div>
            <div class="hl-total"><span>{{ __('partner.net_partner') }}</span><span class="hl-net">{{ $money($b->netDue()) }}</span></div>
            @endif
            @if ($b->nothingDue())
                <p class="hl-muted" style="margin-top:.4rem">{{ __('partner.nothing_due') }}</p>
            @endif
            @if ($b->source !== 'partner' && $s['commissionable'] < $b->total)
                <p class="hl-muted" style="margin-top:.4rem">{{ __('partner.non_commissionable_note') }}</p>
            @endif
            <div class="hl-row" style="margin-top:.6rem;border-top:1px solid #e5e7eb;border-bottom:0">
                <span class="hl-k">{{ __('partner.payment') }}</span>
                <span class="hl-v">@if ($b->nothingDue())<span class="hl-badge hl-badge-gray">{{ __('partner.nothing_due_short') }}</span>@else<span class="hl-badge hl-badge-{{ \App\Filament\Shared\BookingActions::paymentColor($b->payment_status) }}">{{ $payments[$b->payment_status] ?? $b->payment_status }}</span>@endif</span>
            </div>
        </div>

        {{-- Formule(s) réservée(s) --}}
        <div class="hl-card hl-span">
            <h3>{{ __('partner.booked_formula') }}</h3>
            @forelse ($s['lines'] as $l)
                <div @if(! $loop->first) style="margin-top:1rem;padding-top:1rem;border-top:1px solid #e5e7eb" @endif>
                    <div class="hl-formula">{{ $l['treatment_name'] }}
                        <span class="hl-muted" style="font-weight:400">· {{ $l['party'] }} {{ __('partner.pers') }}@if(count($s['lines']) > 1) · #{{ $l['participant_no'] }}@endif</span>
                    </div>
                    @if (! empty($l['steps']))
                        <ul class="hl-steps">
                            @foreach ($l['steps'] as $st)
                                <li><span>{{ $st['label'] }}</span><span class="hl-muted">{{ $st['duration_min'] }} min</span></li>
                            @endforeach
                        </ul>
                    @endif
                    <div class="hl-row" style="border-bottom:0"><span class="hl-k">{{ __('partner.total_duration') }}</span><span class="hl-v">{{ $dur($l['duration_min']) }}</span></div>
                    @if (! empty($l['included']))
                        <div class="hl-muted">{{ __('partner.included') }} :</div>
                        <div class="hl-chips">@foreach ($l['included'] as $inc)<span class="hl-chip">{{ $inc }}</span>@endforeach</div>
                    @endif
                    @if (! empty($l['extras']))
                        <div class="hl-muted" style="margin-top:.6rem">{{ __('partner.extras') }} :</div>
                        @foreach ($l['extras'] as $e)
                            <div class="hl-extra">
                                @php($mult = ($e['qty'] ?? 1) * (! empty($e['per_person']) ? $l['party'] : 1))
                                <span>{{ $e['name'] }}@if(! empty($e['extra_min']))<span class="hl-muted"> — +{{ $e['extra_min'] }} min</span>@endif
                                    @if ($mult > 1)<span class="hl-muted"> — {{ $money($e['unit_price']) }} × {{ $mult }}@if(! empty($e['per_person'])) {{ __('partner.pers') }}@endif</span>@endif</span>
                                <span>{{ $money($e['price']) }}</span>
                            </div>
                        @endforeach
                    @endif
                    <div class="hl-row" style="border-bottom:0"><span class="hl-k">{{ __('partner.price') }}</span><span class="hl-v">{{ $money($l['price']) }}</span></div>
                </div>
            @empty
                @foreach ($b->participants as $p)
                    <div class="hl-formula">{{ $p->treatment_name }} <span class="hl-muted" style="font-weight:400">· {{ $p->party }} {{ __('partner.pers') }} · {{ $dur($p->duration_min) }}</span></div>
                @endforeach
            @endforelse
        </div>

        {{-- Demandes d'annulation --}}
        @if ($b->cancellationRequests->isNotEmpty())
            <div class="hl-card hl-span">
                <h3>{{ __('partner.cancellation_requests') }}</h3>
                @foreach ($b->cancellationRequests as $r)
                    <div class="hl-req" wire:key="req-{{ $r->id }}">
                        <div class="hl-head">
                            <span class="hl-muted">{{ $r->created_at->format('d/m/Y H:i') }}</span>
                            <span class="hl-badge hl-badge-{{ \App\Filament\Shared\BookingActions::requestColor($r->status) }}">{{ __('partner.cancellation_steps.'.$r->step()) }}</span>
                        </div>
                        @if ($r->reason_code)<p><b>{{ __('partner.cancellation_reason_codes.'.$r->reason_code) }}</b></p>@endif
                        <p>{{ $r->reason }}</p>
                        @if ($r->proposed_start_at)
                            <p class="hl-muted">{{ __('partner.proposed_start_at') }} : {{ $r->proposed_start_at->translatedFormat('j F Y') }} {{ $r->proposed_start_at->format('H\hi') }}@if($r->proposal_response) — {{ __('partner.proposal_responses.'.$r->proposal_response) }}@endif</p>
                        @endif
                        @if ($r->client_response)
                            <p class="hl-muted">{{ __('partner.client_response') }} : {{ __('partner.client_responses.'.$r->client_response) }} ({{ $r->client_responded_at?->format('d/m/Y H:i') }})</p>
                        @endif
                        @if ($r->isPending())
                            <p class="hl-muted">{{ __('partner.cancellation_pending_help') }}</p>
                        @endif
                    </div>
                @endforeach
            </div>
        @endif

        {{-- Signalements client --}}
        @if ($b->incidents->isNotEmpty())
            <div class="hl-card hl-span">
                <h3>{{ __('partner.guest_reports') }}</h3>
                @foreach ($b->incidents as $i)
                    <div class="hl-req" wire:key="inc-{{ $i->id }}">
                        <div class="hl-head">
                            <span class="hl-muted">{{ $i->created_at->format('d/m/Y H:i') }}</span>
                            <span class="hl-badge hl-badge-warning">{{ __('partner.report_categories.'.$i->category) }}</span>
                        </div>
                        <p>{{ $i->description }}</p>
                        <p class="hl-muted">{{ $i->status === 'open' ? __('partner.report_sent_body') : __('partner.report_statuses.'.$i->status) }}</p>
                    </div>
                @endforeach
            </div>
        @endif

        {{-- Notes internes --}}
        <div class="hl-card hl-span">
            <div class="hl-head" style="margin-bottom:.6rem">
                <h3 style="margin:0">{{ __('partner.internal_notes') }}</h3>
                {{ $this->addNoteAction }}
            </div>
            <p class="hl-muted" style="margin-bottom:.6rem">{{ __('partner.internal_notes_help') }}</p>
            @forelse ($b->notes as $n)
                <div class="hl-note" wire:key="note-{{ $n->id }}">
                    <div class="hl-meta">
                        <span>{{ $n->created_at->format('d/m/Y H:i') }}@if($n->user) · {{ $n->user->name }}@endif</span>
                    </div>
                    <p>{{ $n->body }}</p>
                </div>
            @empty
                <p class="hl-muted">{{ __('partner.no_notes') }}</p>
            @endforelse
        </div>

        {{-- Historique --}}
        <div class="hl-card hl-span">
            <h3>{{ __('partner.history') }}</h3>
            @foreach ($b->events->sortByDesc('created_at') as $ev)
                <div class="hl-row"><span class="hl-k">{{ $ev->created_at->format('d/m/Y H:i') }}</span><span class="hl-v">{{ __('partner.events.'.$ev->type) !== 'partner.events.'.$ev->type ? __('partner.events.'.$ev->type) : $ev->type }}@if($ev->actor) <span class="hl-muted">· {{ __('partner.actors.'.$ev->actor) !== 'partner.actors.'.$ev->actor ? __('partner.actors.'.$ev->actor) : $ev->actor }}</span>@endif</span></div>
            @endforeach
        </div>
    </div>

    <x-filament-actions::modals />
</x-filament-panels::page>
