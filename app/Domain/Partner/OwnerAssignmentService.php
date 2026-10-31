<?php

namespace App\Domain\Partner;

use App\Mail\PartnerInviteMail;
use App\Models\ActivityLog;
use App\Models\Partner;
use App\Models\Spa;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Fiches créées par l'Admin puis attribuées au véritable propriétaire.
 *
 * Une fiche créée depuis l'administration est d'abord rattachée au « portefeuille » de l'administrateur
 * (un partenaire technique lui appartenant), ce qui lui permet de la configurer comme un partenaire.
 * L'attribution déplace ensuite la fiche vers le partenaire du propriétaire (compte créé si besoin),
 * qui reçoit une invitation pour définir son mot de passe. L'isolation reste celle du panneau partenaire :
 * un partenaire ne voit que les établissements de son propre partenaire ; l'Admin conserve tous ses droits.
 */
class OwnerAssignmentService
{
    public const HOLDING_COMPANY = 'HammamLuxury — fiches en préparation';

    public function holdingPartner(User $admin): Partner
    {
        abort_unless($admin->isAdmin(), 403);

        return Partner::firstOrCreate(
            ['user_id' => $admin->id],
            ['company_name' => self::HOLDING_COMPANY, 'status' => 'approved'],
        );
    }

    /** @param  array<string, mixed>  $data  name, category, city_id, area, address, phone, email */
    public function createSpa(User $admin, array $data): Spa
    {
        $spa = app(OnboardingService::class)->saveSpa($this->holdingPartner($admin), null, $data);
        ActivityLog::record('spa.created_by_admin', $spa, ['name' => $spa->name]);

        return $spa;
    }

    /**
     * @param  array<string, mixed>  $data  email, first_name, last_name, phone, company_name
     */
    public function assign(Spa $spa, array $data, bool $invite = true): Partner
    {
        $email = Str::lower(trim((string) $data['email']));
        $user = User::where('email', $email)->first();

        if ($user && ! $user->isPartner()) {
            throw ValidationException::withMessages(['email' => __('admin.assign_owner_email_not_partner', ['role' => $user->role])]);
        }

        $created = false;
        if (! $user) {
            $user = User::create([
                'name' => trim(($data['first_name'] ?? '').' '.($data['last_name'] ?? '')) ?: $data['company_name'],
                'first_name' => $data['first_name'] ?? null,
                'last_name' => $data['last_name'] ?? null,
                'email' => $email,
                'password' => Hash::make(Str::password(32)),
                'role' => 'partner',
                'phone' => $data['phone'] ?? null,
                'locale' => $data['locale'] ?? 'fr',
                'email_verified_at' => now(),
            ]);
            $created = true;
        }

        $partner = Partner::firstOrCreate(
            ['user_id' => $user->id],
            ['company_name' => $data['company_name'] ?: $spa->name, 'contact_phone' => $data['phone'] ?? null, 'status' => 'approved'],
        );
        if ($partner->status !== 'approved') {
            $partner->update(['status' => 'approved']);
        }

        $from = $spa->partner;
        if ($spa->partner_id !== $partner->id) {
            $spa->update(['partner_id' => $partner->id]);
        }

        ActivityLog::record('spa.owner_assigned', $spa, [
            'name' => $spa->name,
            'from' => $from?->company_name,
            'to' => $partner->company_name,
            'email' => $email,
            'account_created' => $created,
        ]);

        if ($invite) {
            $this->invite($user, $spa);
        }

        return $partner;
    }

    /** Lien de définition du mot de passe (broker « partners », validité 24 h) envoyé au propriétaire. */
    public function invite(User $user, Spa $spa): void
    {
        $token = Password::broker('partners')->createToken($user);
        Mail::to($user->email)->queue(new PartnerInviteMail($user, $spa, $token));
        $spa->update(['owner_invited_at' => now()]);
        ActivityLog::record('spa.owner_invited', $spa, ['email' => $user->email]);
    }

    /** Propriétaire d'une fiche (null si elle est dans un portefeuille administrateur). */
    public function ownerOf(Spa $spa): ?User
    {
        $user = $spa->partner?->user;

        return $user && $user->isPartner() ? $user : null;
    }

    public function resendInvite(Spa $spa): void
    {
        $owner = $this->ownerOf($spa);
        if (! $owner) {
            throw ValidationException::withMessages(['email' => __('admin.mgmt_no_owner')]);
        }
        $this->invite($owner, $spa);
    }

    /** @param  array<string, mixed>  $data  first_name, last_name, email, phone */
    public function updateOwner(Spa $spa, array $data): User
    {
        $owner = $this->ownerOf($spa);
        if (! $owner) {
            throw ValidationException::withMessages(['email' => __('admin.mgmt_no_owner')]);
        }
        $email = Str::lower(trim((string) $data['email']));
        if ($email !== $owner->email && User::where('email', $email)->where('id', '!=', $owner->id)->exists()) {
            throw ValidationException::withMessages(['email' => __('admin.mgmt_email_taken')]);
        }
        $before = $owner->only(['name', 'email', 'phone']);
        $owner->update([
            'first_name' => $data['first_name'] ?? $owner->first_name,
            'last_name' => $data['last_name'] ?? $owner->last_name,
            'name' => trim(($data['first_name'] ?? $owner->first_name).' '.($data['last_name'] ?? $owner->last_name)) ?: $owner->name,
            'email' => $email,
            'phone' => $data['phone'] ?? $owner->phone,
        ]);
        if ($owner->partner && ! empty($data['phone'])) {
            $owner->partner->update(['contact_phone' => $data['phone']]);
        }
        ActivityLog::record('spa.owner_updated', $spa, ['before' => $before, 'after' => $owner->only(['name', 'email', 'phone'])]);

        return $owner;
    }

    /** Accès du propriétaire à cet établissement refusé (ses autres fiches, les réservations et l'historique restent intacts). */
    public function suspendAccess(Spa $spa, ?string $note = null): User
    {
        $owner = $this->ownerOf($spa);
        if (! $owner) {
            throw ValidationException::withMessages(['email' => __('admin.mgmt_no_owner')]);
        }
        $spa->update(['owner_access_suspended_at' => now()]);
        ActivityLog::record('spa.owner_access_suspended', $spa, array_filter(['email' => $owner->email, 'note' => $note]));

        return $owner;
    }

    public function reactivateAccess(Spa $spa): User
    {
        $owner = $this->ownerOf($spa);
        if (! $owner) {
            throw ValidationException::withMessages(['email' => __('admin.mgmt_no_owner')]);
        }
        $spa->update(['owner_access_suspended_at' => null]);
        ActivityLog::record('spa.owner_access_reactivated', $spa, ['email' => $owner->email]);

        return $owner;
    }

    /** Reprise temporaire : les demandes sont adressées à l'administrateur et décidées par lui ; la fiche reste au propriétaire. */
    public function takeOver(Spa $spa, User $admin, ?string $note = null): void
    {
        abort_unless($admin->isAdmin(), 403);
        $spa->update(['managed_by' => 'admin', 'manager_user_id' => $admin->id]);
        ActivityLog::record('spa.management_taken_over', $spa, array_filter(['manager' => $admin->email, 'note' => $note]));
    }

    public function handBack(Spa $spa): void
    {
        $spa->update(['managed_by' => 'partner', 'manager_user_id' => null]);
        ActivityLog::record('spa.management_handed_back', $spa, ['owner' => $this->ownerOf($spa)?->email]);
    }

    /** État lisible pour l'Admin : portfolio | taken_over | suspended | invited | partner. */
    public function state(Spa $spa): string
    {
        $owner = $this->ownerOf($spa);
        if (! $owner) {
            return 'portfolio';
        }
        if ($spa->managed_by === 'admin') {
            return 'taken_over';
        }
        if ($spa->ownerAccessSuspended()) {
            return 'suspended';
        }

        return 'partner';
    }
}
