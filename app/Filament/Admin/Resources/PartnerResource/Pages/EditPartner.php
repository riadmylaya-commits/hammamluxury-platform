<?php

namespace App\Filament\Admin\Resources\PartnerResource\Pages;

use App\Filament\Admin\Resources\PartnerResource;
use App\Filament\Partner\Pages\BillingProfile;
use App\Models\ActivityLog;
use Filament\Resources\Pages\EditRecord;

class EditPartner extends EditRecord
{
    protected static string $resource = PartnerResource::class;

    protected function mutateFormDataBeforeSave(array $data): array
    {
        if (! empty($data['ice_not_applicable'])) {
            $data['ice'] = null;
        } else {
            $data['ice_not_applicable_reason'] = null;
        }

        return $data;
    }

    protected function afterSave(): void
    {
        $changed = array_keys(array_intersect_key($this->record->getChanges(), array_flip(BillingProfile::fields())));
        if ($changed !== []) {
            ActivityLog::record('partner.billing_profile_updated', $this->record, ['changed' => $changed, 'by' => 'admin']);
        }
    }
}
