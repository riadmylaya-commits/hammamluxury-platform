<?php

namespace App\Domain\Booking;

use App\Mail\GuestReportMail;
use App\Models\ActivityLog;
use App\Models\Booking;
use App\Models\ClientIncident;
use App\Models\User;
use Illuminate\Support\Facades\Mail;

/** Signalements de comportement client par l'établissement : conservés, visibles de l'administration, jamais de sanction automatique. */
class IncidentService
{
    public function reportGuest(Booking $booking, ?User $reporter, string $category, string $description, ?string $evidencePath = null): ClientIncident
    {
        if (! in_array($category, ClientIncident::CATEGORIES, true)) {
            throw BookingException::make('category', 'invalid', [], 422);
        }
        $description = trim($description);
        if (mb_strlen($description) < 20) {
            throw BookingException::make('description', 'guest_report_description_required', [], 422);
        }
        if (! $booking->canReportGuest()) {
            throw BookingException::make('status', 'guest_report_not_allowed', [], 409);
        }

        $incident = $booking->incidents()->create([
            'spa_id' => $booking->spa_id,
            'reported_by' => $reporter?->id,
            'client_key' => $booking->clientKey(),
            'client_phone' => $booking->phone,
            'client_email' => $booking->email,
            'category' => $category,
            'description' => $description,
            'evidence_path' => $evidencePath,
            'status' => 'open',
        ]);
        $booking->log('guest:reported', 'partner', ['incident' => $incident->id, 'category' => $category]);
        ActivityLog::record('booking.guest_reported', $booking, ['ref' => $booking->reference, 'category' => $category], 'partner');

        foreach (User::where('role', 'admin')->get() as $admin) {
            Mail::to($admin->email)->queue((new GuestReportMail($incident))->locale($admin->locale ?: 'fr'));
        }

        return $incident;
    }

    public function review(ClientIncident $incident, User $admin, string $status, ?string $note = null): ClientIncident
    {
        if (! in_array($status, ['reviewed', 'dismissed'], true)) {
            throw BookingException::make('status', 'invalid', [], 422);
        }
        $incident->update(['status' => $status, 'admin_note' => $note ? trim($note) : null, 'reviewed_by' => $admin->id, 'reviewed_at' => now()]);
        $incident->booking->log('guest:report_'.$status, 'admin', ['incident' => $incident->id]);
        ActivityLog::record('booking.guest_report_'.$status, $incident->booking, array_filter(['ref' => $incident->booking->reference, 'note' => $note]), 'admin');

        return $incident;
    }
}
