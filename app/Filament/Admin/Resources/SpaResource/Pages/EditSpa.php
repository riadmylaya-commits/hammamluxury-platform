<?php

namespace App\Filament\Admin\Resources\SpaResource\Pages;

use App\Domain\Catalogue\CapacityReadiness;
use App\Domain\Catalogue\PublicationChecklist;
use App\Domain\Partner\OnboardingService;
use App\Filament\Admin\Resources\SpaResource;
use App\Models\ActivityLog;
use App\Models\Spa;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

class EditSpa extends EditRecord
{
    protected static string $resource = SpaResource::class;

    private ?string $statusBefore = null;

    protected function mutateFormDataBeforeSave(array $data): array
    {
        /** @var Spa $spa */
        $spa = $this->record;
        $this->statusBefore = $spa->status;
        if (($data['status'] ?? null) === 'published' && $spa->status !== 'published' && ! PublicationChecklist::passes($spa)) {
            Notification::make()->title(__('admin.checklist_blocking'))->body(implode(' · ', PublicationChecklist::failures($spa)))->danger()->send();
            $this->halt();
        }
        if (($data['status'] ?? null) === 'published') {
            $data['published_at'] = $spa->published_at ?? now();
        }
        $wantsInstant = (bool) ($data['instant_booking'] ?? false);
        if ($wantsInstant && ! $spa->instant_booking && ! CapacityReadiness::passes($spa)) {
            Notification::make()->title(__('admin.readiness_ko'))->body(implode(' · ', CapacityReadiness::failures($spa)))->danger()->send();
            $this->halt();
        }
        if ($wantsInstant !== (bool) $spa->instant_booking) {
            $data['instant_booking_at'] = $wantsInstant ? now() : null;
            ActivityLog::record($wantsInstant ? 'spa.instant_booking_on' : 'spa.instant_booking_off', $spa);
        }

        return $data;
    }

    protected function afterSave(): void
    {
        /** @var Spa $spa */
        $spa = $this->record;
        $before = $this->statusBefore;
        if ($before !== $spa->status && $spa->status === 'published') {
            OnboardingService::notifyDecision($spa, 'published');
        } elseif ($before === 'pending' && $spa->status === 'draft') {
            OnboardingService::notifyDecision($spa, 'refused');
        } elseif ($before !== $spa->status && $spa->status === 'suspended') {
            ActivityLog::record('spa.suspended', $spa, array_filter(['note' => $spa->status_note]));
        }
    }
}
