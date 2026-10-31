<?php

namespace App\Filament\Admin\Resources;

use App\Domain\Booking\CancellationService;
use App\Domain\Catalogue\CapacityReadiness;
use App\Domain\Catalogue\PublicationChecklist;
use App\Domain\Partner\OnboardingService;
use App\Domain\Partner\OwnerAssignmentService;
use App\Filament\Admin\Resources\SpaResource\Pages;
use App\Filament\Admin\Resources\SpaResource\RelationManagers;
use App\Filament\Forms\Components\PhoneField;
use App\Filament\Partner\Forms\SpaForm;
use App\Models\Spa;
use App\Models\User;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;

class SpaResource extends Resource
{
    protected static ?string $model = Spa::class;

    protected static ?string $navigationIcon = 'heroicon-o-building-storefront';

    protected static ?int $navigationSort = 20;

    public static function getModelLabel(): string
    {
        return __('admin.spa');
    }

    public static function getPluralModelLabel(): string
    {
        return __('admin.spas');
    }

    public static function getNavigationBadge(): ?string
    {
        $n = Spa::where('status', 'pending')->count();

        return $n ? (string) $n : null;
    }

    public static function statuses(): array
    {
        return collect(Spa::STATUSES)->mapWithKeys(fn ($s) => [$s => __("partner.spa_status_$s")])->all();
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make(__('admin.validation'))->schema([
                Forms\Components\Select::make('status')->label(__('partner.status'))->options(self::statuses())->required(),
                Forms\Components\TextInput::make('slug')->label('Slug')->required()->maxLength(120)->unique(ignoreRecord: true),
                Forms\Components\Textarea::make('status_note')->label(__('admin.status_note'))->rows(3)->helperText(__('admin.status_note_help'))->columnSpanFull(),
            ])->columns(2),
            Forms\Components\Section::make(__('partner.license_section'))->description(__('admin.license_admin_help'))->schema([
                Forms\Components\TextInput::make('license_number')->label(__('partner.license_number'))->maxLength(60),
                Forms\Components\Select::make('license_authority')->label(__('partner.license_authority'))->options(SpaForm::licenseAuthorities())->native(false)->placeholder('—')->live(),
                Forms\Components\TextInput::make('license_authority_other')->label(__('partner.license_authority_other_name'))->maxLength(120)
                    ->visible(fn (Forms\Get $get) => $get('license_authority') === 'other'),
                Forms\Components\Placeholder::make('license_warning')->label('')->columnSpanFull()
                    ->visible(fn (Spa $s) => $s->licenseExpected() && blank($s->license_number))
                    ->content(new HtmlString('<span class="font-medium" style="color: rgb(var(--warning-600))">'.e(__('admin.license_missing_warning')).'</span>')),
            ])->columns(2),
            Forms\Components\Section::make(__('admin.checklist'))->schema([
                Forms\Components\Placeholder::make('checks')->label('')->content(fn (Spa $s) => self::checklist($s)),
            ]),
            Forms\Components\Section::make(__('admin.readiness'))->description(__('admin.readiness_help'))->schema([
                Forms\Components\Placeholder::make('readiness')->label('')->content(fn (Spa $s) => self::readiness($s)),
                Forms\Components\Toggle::make('instant_booking')->label(__('admin.instant_booking'))->helperText(__('admin.instant_booking_help'))
                    ->disabled(fn (Spa $s) => ! CapacityReadiness::passes($s) && ! $s->instant_booking),
                Forms\Components\Placeholder::make('instant_booking_at')->label(__('admin.instant_booking_at'))->visible(fn (Spa $s) => $s->instant_booking)
                    ->content(fn (Spa $s) => $s->instant_booking_at?->format('d/m/Y H:i') ?? '—'),
                Forms\Components\TextInput::make('waiting_ttl_hours')->label(__('admin.waiting_ttl'))->numeric()->minValue(0.5)->maxValue(72)->step(0.5)->suffix('h')
                    ->placeholder((string) config('hl.waiting_ttl_hours'))->helperText(__('admin.waiting_ttl_help', ['default' => config('hl.waiting_ttl_hours')])),
            ]),
        ]);
    }

    public static function readiness(Spa $spa): HtmlString
    {
        $html = '<ul class="space-y-1 text-sm">';
        foreach (CapacityReadiness::checks($spa) as $label => $ok) {
            $html .= '<li>'.($ok ? '✅' : '❌').' '.e($label).'</li>';
        }
        $html .= '<li class="font-semibold '.(CapacityReadiness::passes($spa) ? 'text-success-600' : 'text-danger-600').'">'.e(CapacityReadiness::passes($spa) ? __('admin.readiness_ok') : __('admin.readiness_ko')).'</li></ul>';

        return new HtmlString($html);
    }

    public static function checklist(Spa $spa): HtmlString
    {
        $html = '<ul class="space-y-1">';
        foreach (PublicationChecklist::checks($spa) as $label => $ok) {
            $html .= '<li class="'.($ok ? 'text-success-600' : 'text-danger-600 font-medium').'">'.($ok ? '[OK]' : '[!!]').' '.e($label).'</li>';
        }
        if ($spa->licenseExpected() && blank($spa->license_number)) {
            $html .= '<li class="font-medium" style="color: rgb(var(--warning-600))">[ ? ] '.e(__('admin.license_missing_warning')).'</li>';
        }
        if (! PublicationChecklist::passes($spa)) {
            $html .= '<li class="font-semibold text-danger-600">'.e(__('admin.checklist_blocking')).'</li>';
        }

        return new HtmlString($html.'</ul>');
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['partner', 'photos']))
            ->columns([
                Tables\Columns\ImageColumn::make('cover')->label('')->getStateUsing(fn (Spa $s) => $s->coverPhoto()?->url())->circular(),
                Tables\Columns\TextColumn::make('name')->label(__('admin.spa'))->searchable()->weight('bold')
                    ->description(fn (Spa $s) => $s->area ? "$s->area · $s->city" : $s->city),
                Tables\Columns\TextColumn::make('partner.company_name')->label(__('admin.partner'))->searchable(),
                Tables\Columns\TextColumn::make('management')->label(__('admin.mgmt_section'))->badge()
                    ->state(fn (Spa $s) => app(OwnerAssignmentService::class)->state($s))
                    ->formatStateUsing(fn (string $state) => __('admin.mgmt_state_'.$state))
                    ->color(fn (string $state) => match ($state) {
                        'portfolio' => 'info', 'taken_over' => 'warning', 'suspended' => 'danger', default => 'success'
                    })
                    ->description(fn (Spa $s) => $s->owner_invited_at && app(OwnerAssignmentService::class)->ownerOf($s) ? __('admin.mgmt_invited_on', ['date' => $s->owner_invited_at->format('d/m/Y')]) : null),
                Tables\Columns\TextColumn::make('treatments_count')->counts('treatments')->label(__('partner.treatments')),
                Tables\Columns\TextColumn::make('bookings_count')->counts('bookings')->label(__('partner.bookings')),
                Tables\Columns\TextColumn::make('cancellation_requests_count')->counts('cancellationRequests')->label(__('admin.cancellation_col'))->sortable()
                    ->formatStateUsing(function ($state, Spa $s) {
                        $rate = CancellationService::rateFor($s->id);

                        return $state ? $state.' ('.rtrim(rtrim(number_format($rate['rate'], 1, ',', ''), '0'), ',').' %)' : '0';
                    })
                    ->color(fn ($state, Spa $s) => $state && CancellationService::rateFor($s->id)['rate'] >= 20 ? 'danger' : ($state ? 'warning' : 'gray'))->badge()
                    ->tooltip(__('admin.cancellation_col_help')),
                Tables\Columns\TextColumn::make('full_declines_count')->label(__('admin.declines_col'))->badge()->sortable()
                    ->counts(['declines as full_declines_count' => fn (Builder $q) => $q->where('reason', 'full')->where('created_at', '>=', now()->subDays(90))])
                    ->formatStateUsing(fn ($state, Spa $s) => $state ? $state.' ('.$s->declines()->where('reason', 'full')->where('engine_available', true)->where('created_at', '>=', now()->subDays(90))->count().')' : '0')
                    ->color(fn ($state, Spa $s) => $state && $s->declines()->where('reason', 'full')->where('engine_available', true)->where('created_at', '>=', now()->subDays(90))->exists() ? 'danger' : ($state ? 'warning' : 'gray'))
                    ->tooltip(__('admin.declines_col_help'))
                    ->url(fn (Spa $s) => BookingDeclineResource::getUrl('index', ['tableFilters' => ['spa_id' => ['value' => $s->id], 'reason' => ['value' => 'full']]])),
                Tables\Columns\TextColumn::make('rating')->label(__('admin.rating'))->placeholder('—'),
                Tables\Columns\TextColumn::make('license_number')->label(__('admin.license_col'))->toggleable()->badge()
                    ->getStateUsing(fn (Spa $s) => $s->license_number ?: ($s->licenseExpected() ? __('admin.license_missing') : null))->placeholder('—')
                    ->color(fn (Spa $s) => blank($s->license_number) ? 'warning' : 'gray')
                    ->description(fn (Spa $s) => $s->licenseAuthorityLabel()),
                Tables\Columns\TextColumn::make('status')->label(__('partner.status'))->badge()
                    ->formatStateUsing(fn ($state) => self::statuses()[$state] ?? $state)
                    ->color(fn ($state) => match ($state) {
                        'published' => 'success', 'pending' => 'warning', 'suspended' => 'danger', default => 'gray'
                    }),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')->options(self::statuses()),
                Tables\Filters\Filter::make('without_license')->label(__('admin.filter_without_license'))->toggle()
                    ->query(fn (Builder $query) => $query->whereNull('license_number')),
                Tables\Filters\Filter::make('hammam_without_license')->label(__('admin.filter_hammam_without_license'))->toggle()
                    ->query(fn (Builder $query) => $query->whereNull('license_number')->whereIn('category', Spa::LICENSE_EXPECTED_CATEGORIES)),
            ])
            ->actions([
                Tables\Actions\Action::make('publish')->label(__('admin.publish'))->icon('heroicon-o-eye')->color('success')
                    ->visible(fn (Spa $s) => $s->status !== 'published')->requiresConfirmation()
                    ->modalContent(fn (Spa $s) => self::checklist($s))
                    ->modalSubmitAction(fn ($action, Spa $s) => $action->disabled(! PublicationChecklist::passes($s))->extraAttributes(fn () => PublicationChecklist::passes($s) ? [] : ['disabled' => 'disabled']))
                    ->action(function (Spa $s) {
                        if (! PublicationChecklist::passes($s)) {
                            Notification::make()->title(__('admin.checklist_blocking'))->body(implode(' · ', PublicationChecklist::failures($s)))->danger()->send();

                            return;
                        }
                        $s->refreshPriceFrom();
                        $s->update(['status' => 'published', 'published_at' => $s->published_at ?? now(), 'status_note' => null]);
                        OnboardingService::notifyDecision($s, 'published');
                        Notification::make()->title(__('admin.published_ok'))->success()->send();
                    }),
                Tables\Actions\Action::make('refuse')->label(__('admin.refuse'))->icon('heroicon-o-x-mark')->color('danger')
                    ->visible(fn (Spa $s) => $s->status === 'pending')
                    ->form([Forms\Components\Textarea::make('status_note')->label(__('admin.refuse_reason'))->required()->rows(4)->maxLength(2000)])
                    ->action(function (Spa $s, array $data) {
                        $s->update(['status' => 'draft', 'status_note' => $data['status_note']]);
                        OnboardingService::notifyDecision($s, 'refused');
                        Notification::make()->title(__('admin.refused_ok'))->success()->send();
                    }),
                Tables\Actions\Action::make('preview')->label(__('admin.preview'))->icon('heroicon-o-arrow-top-right-on-square')
                    ->url(fn (Spa $s) => route('spa.show', ['locale' => 'fr', 'spa' => $s]), shouldOpenInNewTab: true)
                    ->visible(fn (Spa $s) => $s->status === 'published'),
                Tables\Actions\Action::make('manage')->label(__('admin.manage'))->icon('heroicon-o-wrench-screwdriver')
                    ->url(fn (Spa $s) => url("/partenaire/{$s->slug}")),
                Tables\Actions\ActionGroup::make([
                    Tables\Actions\Action::make('assign_owner')->label(fn (Spa $s) => app(OwnerAssignmentService::class)->ownerOf($s) ? __('admin.assign_owner') : __('admin.mgmt_invite_owner'))->icon('heroicon-o-user-plus')->color('warning')
                        ->modalDescription(__('admin.assign_owner_help'))
                        ->fillForm(fn (Spa $s) => ['company_name' => $s->partner?->user?->isAdmin() ? $s->name : $s->partner?->company_name, 'invite' => true])
                        ->form([
                            Forms\Components\Placeholder::make('current')->label(__('admin.assign_owner_current'))
                                ->content(fn (Spa $s) => ($s->partner?->user?->isAdmin() ? __('admin.holding_badge') : $s->partner?->company_name).' — '.$s->partner?->user?->email),
                            Forms\Components\Grid::make(2)->schema([
                                Forms\Components\TextInput::make('first_name')->label(__('partner.first_name'))->required()->maxLength(100),
                                Forms\Components\TextInput::make('last_name')->label(__('partner.last_name'))->required()->maxLength(100),
                            ]),
                            Forms\Components\TextInput::make('email')->label('E-mail')->email()->required()->maxLength(190)
                                ->rules([fn () => function (string $attribute, mixed $value, \Closure $fail) {
                                    $user = User::where('email', Str::lower(trim((string) $value)))->first();
                                    if ($user && ! $user->isPartner()) {
                                        $fail(__('admin.assign_owner_email_not_partner', ['role' => $user->role]));
                                    }
                                }]),
                            PhoneField::make('phone', __('partner.phone'), nativeCountry: true),
                            Forms\Components\TextInput::make('company_name')->label(__('admin.assign_owner_company'))->required()->maxLength(190),
                            Forms\Components\Toggle::make('invite')->label(__('admin.assign_owner_invite'))->default(true),
                        ])
                        ->action(function (Spa $s, array $data) {
                            app(OwnerAssignmentService::class)->assign($s, $data, (bool) ($data['invite'] ?? true));
                            Notification::make()->title(__('admin.assign_owner_ok', ['email' => $data['email']]))->success()->send();
                        }),
                    Tables\Actions\Action::make('resend_invite')->label(__('admin.mgmt_resend_invite'))->icon('heroicon-o-envelope')
                        ->visible(fn (Spa $s) => (bool) app(OwnerAssignmentService::class)->ownerOf($s))
                        ->requiresConfirmation()->modalDescription(__('admin.mgmt_resend_invite_help'))
                        ->action(function (Spa $s) {
                            app(OwnerAssignmentService::class)->resendInvite($s);
                            Notification::make()->title(__('admin.mgmt_done'))->success()->send();
                        }),
                    Tables\Actions\Action::make('edit_owner')->label(__('admin.mgmt_edit_owner'))->icon('heroicon-o-pencil-square')
                        ->visible(fn (Spa $s) => (bool) app(OwnerAssignmentService::class)->ownerOf($s))
                        ->fillForm(fn (Spa $s) => ['first_name' => app(OwnerAssignmentService::class)->ownerOf($s)?->first_name, 'last_name' => app(OwnerAssignmentService::class)->ownerOf($s)?->last_name, 'email' => app(OwnerAssignmentService::class)->ownerOf($s)?->email, 'phone' => app(OwnerAssignmentService::class)->ownerOf($s)?->phone])
                        ->form([
                            Forms\Components\Grid::make(2)->schema([
                                Forms\Components\TextInput::make('first_name')->label(__('partner.first_name'))->required()->maxLength(100),
                                Forms\Components\TextInput::make('last_name')->label(__('partner.last_name'))->required()->maxLength(100),
                            ]),
                            Forms\Components\TextInput::make('email')->label('E-mail')->email()->required()->maxLength(190)
                                ->rules([
                                    fn (Spa $s) => function (string $attribute, mixed $value, \Closure $fail) use ($s) {
                                        $owner = app(OwnerAssignmentService::class)->ownerOf($s);
                                        $taken = User::where('email', Str::lower(trim((string) $value)))
                                            ->when($owner, fn ($q) => $q->where('id', '!=', $owner->id))->exists();
                                        if ($taken) {
                                            $fail(__('admin.mgmt_email_taken'));
                                        }
                                    },
                                ]),
                            PhoneField::make('phone', __('partner.phone'), nativeCountry: true),
                        ])
                        ->action(function (Spa $s, array $data) {
                            app(OwnerAssignmentService::class)->updateOwner($s, $data);
                            Notification::make()->title(__('admin.mgmt_done'))->success()->send();
                        }),
                    Tables\Actions\Action::make('suspend_access')->label(__('admin.mgmt_suspend'))->icon('heroicon-o-lock-closed')->color('danger')
                        ->visible(fn (Spa $s) => app(OwnerAssignmentService::class)->ownerOf($s) && ! $s->ownerAccessSuspended())
                        ->form([Forms\Components\Textarea::make('note')->label(__('admin.mgmt_note'))->rows(2)->maxLength(500)])
                        ->requiresConfirmation()->modalDescription(__('admin.mgmt_suspend_help'))
                        ->action(function (Spa $s, array $data) {
                            app(OwnerAssignmentService::class)->suspendAccess($s, $data['note'] ?? null);
                            Notification::make()->title(__('admin.mgmt_done'))->success()->send();
                        }),
                    Tables\Actions\Action::make('reactivate_access')->label(__('admin.mgmt_reactivate'))->icon('heroicon-o-lock-open')->color('success')
                        ->visible(fn (Spa $s) => app(OwnerAssignmentService::class)->ownerOf($s) && $s->ownerAccessSuspended())
                        ->requiresConfirmation()
                        ->action(function (Spa $s) {
                            app(OwnerAssignmentService::class)->reactivateAccess($s);
                            Notification::make()->title(__('admin.mgmt_done'))->success()->send();
                        }),
                    Tables\Actions\Action::make('take_over')->label(__('admin.mgmt_take_over'))->icon('heroicon-o-hand-raised')->color('warning')
                        ->visible(fn (Spa $s) => app(OwnerAssignmentService::class)->ownerOf($s) && $s->managed_by !== 'admin')
                        ->form([Forms\Components\Textarea::make('note')->label(__('admin.mgmt_note'))->rows(2)->maxLength(500)])
                        ->requiresConfirmation()->modalDescription(__('admin.mgmt_take_over_help'))
                        ->action(function (Spa $s, array $data) {
                            app(OwnerAssignmentService::class)->takeOver($s, auth()->user(), $data['note'] ?? null);
                            Notification::make()->title(__('admin.mgmt_done'))->success()->send();
                        }),
                    Tables\Actions\Action::make('hand_back')->label(__('admin.mgmt_hand_back'))->icon('heroicon-o-arrow-uturn-left')->color('success')
                        ->visible(fn (Spa $s) => $s->managed_by === 'admin')
                        ->requiresConfirmation()
                        ->action(function (Spa $s) {
                            app(OwnerAssignmentService::class)->handBack($s);
                            Notification::make()->title(__('admin.mgmt_done'))->success()->send();
                        }),
                ])->label(__('admin.mgmt_section'))->icon('heroicon-o-users')->button()->color('gray'),
                Tables\Actions\EditAction::make(),
            ]);
    }

    public static function getRelations(): array
    {
        return [RelationManagers\ExtrasRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListSpas::route('/'),
            'edit' => Pages\EditSpa::route('/{record}/edit'),
        ];
    }
}
