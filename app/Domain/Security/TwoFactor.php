<?php

namespace App\Domain\Security;

use App\Models\ActivityLog;
use App\Models\User;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use PragmaRX\Google2FA\Google2FA;

/**
 * Double authentification TOTP (RFC 6238) : secret chiffré, codes de secours hachés,
 * protection anti-rejeu, journal des activations / vérifications dans activity_logs.
 */
class TwoFactor
{
    public const RECOVERY_CODES = 8;

    private Google2FA $engine;

    public function __construct()
    {
        $this->engine = new Google2FA;
        $this->engine->setWindow(1);
    }

    public function generateSecret(): string
    {
        return $this->engine->generateSecretKey(32);
    }

    public function qrSvg(User $user, string $secret): string
    {
        $url = $this->engine->getQRCodeUrl(config('app.name', 'HammamLuxury'), $user->email, $secret);
        $renderer = new ImageRenderer(new RendererStyle(220, 0), new SvgImageBackEnd);

        return (new Writer($renderer))->writeString($url);
    }

    /** Vérifie un code TOTP contre un secret (activation). */
    public function verifySecret(string $secret, string $code): bool
    {
        return $this->engine->verifyKey($secret, $this->clean($code)) !== false;
    }

    /** Active la 2FA après vérification du premier code. @return list<string> codes de secours en clair */
    public function enable(User $user, string $secret, string $code): ?array
    {
        if (! $this->verifySecret($secret, $code)) {
            return null;
        }

        $codes = $this->newRecoveryCodes();
        $user->forceFill([
            'two_factor_secret' => $secret,
            'two_factor_recovery_codes' => array_map(fn ($c) => Hash::make($c), $codes),
            'two_factor_confirmed_at' => now(),
            'two_factor_last_used_at' => $this->engine->getTimestamp(),
        ])->save();

        ActivityLog::record('auth.2fa_enabled', $user);
        $this->markPassed($user);

        return $codes;
    }

    public function disable(User $user): void
    {
        $user->forceFill([
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
            'two_factor_last_used_at' => null,
        ])->save();

        ActivityLog::record('auth.2fa_disabled', $user);
        session()->forget($this->sessionKey($user));
    }

    /** @return list<string> */
    public function regenerateRecoveryCodes(User $user): array
    {
        $codes = $this->newRecoveryCodes();
        $user->forceFill(['two_factor_recovery_codes' => array_map(fn ($c) => Hash::make($c), $codes)])->save();
        ActivityLog::record('auth.2fa_recovery_regenerated', $user);

        return $codes;
    }

    /** Vérification au login : code TOTP (anti-rejeu) ou code de secours (usage unique). */
    public function challenge(User $user, string $code): bool
    {
        $code = $this->clean($code);

        if ($user->two_factor_secret && strlen($code) === 6) {
            $ts = $this->engine->verifyKeyNewer($user->two_factor_secret, $code, (int) $user->two_factor_last_used_at);
            if ($ts !== false) {
                $user->forceFill(['two_factor_last_used_at' => $ts])->save();
                $this->markPassed($user);
                ActivityLog::record('auth.2fa_passed', $user, ['method' => 'totp']);

                return true;
            }
        }

        if ($this->consumeRecoveryCode($user, $code)) {
            $this->markPassed($user);
            ActivityLog::record('auth.2fa_passed', $user, ['method' => 'recovery', 'remaining' => count($user->two_factor_recovery_codes ?? [])]);

            return true;
        }

        ActivityLog::record('auth.2fa_failed', $user, ['ip' => request()->ip()]);

        return false;
    }

    public function hasPassed(User $user): bool
    {
        return (bool) session($this->sessionKey($user));
    }

    public function markPassed(User $user): void
    {
        session([$this->sessionKey($user) => now()->timestamp]);
    }

    private function consumeRecoveryCode(User $user, string $code): bool
    {
        $hashes = $user->two_factor_recovery_codes ?? [];
        foreach ($hashes as $i => $hash) {
            if (Hash::check($code, $hash)) {
                unset($hashes[$i]);
                $user->forceFill(['two_factor_recovery_codes' => array_values($hashes)])->save();

                return true;
            }
        }

        return false;
    }

    /** @return list<string> */
    private function newRecoveryCodes(): array
    {
        return collect(range(1, self::RECOVERY_CODES))
            ->map(fn () => Str::upper(Str::random(5)).'-'.Str::upper(Str::random(5)))
            ->all();
    }

    private function clean(string $code): string
    {
        return Str::upper(str_replace(' ', '', trim($code)));
    }

    private function sessionKey(User $user): string
    {
        return 'hl.2fa_passed.'.$user->getKey();
    }
}
