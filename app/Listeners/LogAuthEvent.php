<?php

namespace App\Listeners;

use App\Models\ActivityLog;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;

/** Journal des connexions (réussies, échouées, déconnexions) dans activity_logs. */
class LogAuthEvent
{
    public function handle(Login|Failed|Logout $event): void
    {
        $request = request();
        $panel = Filament::getCurrentPanel()?->getId() ?? $this->panelFromPath($request->path());
        $props = ['ip' => $request->ip(), 'agent' => mb_substr((string) $request->userAgent(), 0, 190), 'panel' => $panel];
        $user = $event->user instanceof User ? $event->user : null;

        [$action, $props] = match (true) {
            $event instanceof Login => ['auth.login', $props],
            $event instanceof Logout => ['auth.logout', $props],
            default => ['auth.login_failed', $props + ['email' => $event->credentials['email'] ?? null]],
        };

        ActivityLog::create([
            'user_id' => $user?->getKey(),
            'panel' => $panel,
            'action' => $action,
            'subject_type' => $user?->getMorphClass(),
            'subject_id' => $user?->getKey(),
            'properties' => $props,
            'ip' => $request->ip(),
        ]);
    }

    private function panelFromPath(string $path): string
    {
        return match (true) {
            str_starts_with($path, 'admin') => 'admin',
            str_starts_with($path, 'partenaire') => 'partner',
            default => 'site',
        };
    }
}
