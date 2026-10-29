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
        ActivityLog::record('spa.owner_invited', $spa, ['email' => $user->email]);
    }
}
