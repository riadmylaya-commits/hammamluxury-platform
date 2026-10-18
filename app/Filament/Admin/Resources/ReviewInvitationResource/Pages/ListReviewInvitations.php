<?php

namespace App\Filament\Admin\Resources\ReviewInvitationResource\Pages;

use App\Filament\Admin\Resources\ReviewInvitationResource;
use Filament\Resources\Components\Tab;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;

class ListReviewInvitations extends ListRecords
{
    protected static string $resource = ReviewInvitationResource::class;

    public function getTabs(): array
    {
        $tab = fn (string $key, array $statuses) => Tab::make(__("admin.ri_tab.$key"))
            ->badge(fn () => ReviewInvitationResource::getEloquentQuery()->whereIn('invitation_status', $statuses)->count())
            ->modifyQueryUsing(fn (Builder $query) => $query->whereIn('invitation_status', $statuses));

        return [
            'all' => Tab::make(__('admin.ri_tab.all'))->badge(fn () => ReviewInvitationResource::getEloquentQuery()->count()),
            'sent' => $tab('sent', ['sent', 'queued', 'invited_untracked']),
            'send_failed' => $tab('send_failed', ['send_failed']),
            'pending' => $tab('pending', ['pending']),
            'published' => $tab('published', ['published']),
            'rejected' => $tab('rejected', ['rejected']),
            'not_applicable' => $tab('not_applicable', ['not_applicable']),
            'not_due' => $tab('not_due', ['not_due']),
            'expired' => $tab('expired', ['expired']),
        ];
    }

    public function getSubheading(): ?string
    {
        return __('admin.ri_subheading');
    }

    protected function getHeaderActions(): array
    {
        return [];
    }
}
