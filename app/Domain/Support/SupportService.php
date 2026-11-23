<?php

namespace App\Domain\Support;

use App\Mail\SupportMail;
use App\Models\Spa;
use App\Models\SupportRequest;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/** Assistance écrite : une demande enregistrée, un accusé de réception au demandeur, une alerte à l'administration. */
class SupportService
{
    public function open(string $source, string $name, string $email, string $subject, string $body, string $locale, ?Spa $spa = null, ?User $user = null): SupportRequest
    {
        $request = SupportRequest::create([
            'reference' => $this->reference(), 'source' => $source, 'status' => 'new', 'name' => $name, 'email' => $email,
            'subject' => $subject, 'body' => $body, 'locale' => $locale, 'spa_id' => $spa?->id, 'user_id' => $user?->id,
        ]);

        Mail::to($email)->send(new SupportMail($request, 'ack'));
        Mail::to(config('hl.contact_email'))->send(new SupportMail($request, 'admin'));

        return $request;
    }

    /** Réponse de l'administration : conservée dans l'historique et envoyée par e-mail au demandeur. */
    public function reply(SupportRequest $request, string $body, User $admin): void
    {
        DB::transaction(function () use ($request, $body, $admin) {
            $request->messages()->create(['sender' => 'admin', 'user_id' => $admin->id, 'body' => $body]);
            if ($request->status === 'new') {
                $request->update(['status' => 'in_progress']);
            }
        });
        Mail::to($request->email)->send(new SupportMail($request, 'reply', $body));
    }

    /** Complément du partenaire sur une demande existante : rouvre une demande résolue. */
    public function followUp(SupportRequest $request, string $body, User $user): void
    {
        DB::transaction(function () use ($request, $body, $user) {
            $request->messages()->create(['sender' => 'requester', 'user_id' => $user->id, 'body' => $body]);
            if ($request->status === 'resolved') {
                $request->update(['status' => 'in_progress', 'resolved_at' => null]);
            }
        });
        Mail::to(config('hl.contact_email'))->send(new SupportMail($request, 'admin', $body));
    }

    public function setStatus(SupportRequest $request, string $status): void
    {
        abort_unless(in_array($status, SupportRequest::STATUSES, true), 422);
        $request->update(['status' => $status, 'resolved_at' => $status === 'resolved' ? now() : null]);
    }

    private function reference(): string
    {
        do {
            $ref = 'AS'.strtoupper(Str::random(6));
        } while (SupportRequest::where('reference', $ref)->exists());

        return $ref;
    }
}
