<?php

namespace App\Models;

use Filament\Models\Contracts\FilamentUser;
use Filament\Models\Contracts\HasTenants;
use Filament\Panel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Collection;

class User extends Authenticatable implements FilamentUser, HasTenants
{
    use HasFactory, Notifiable;

    public const ROLES = ['admin', 'partner', 'client'];

    protected $fillable = ['name', 'email', 'password', 'role', 'phone', 'locale'];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return ['email_verified_at' => 'datetime', 'password' => 'hashed'];
    }

    public function partner(): HasOne
    {
        return $this->hasOne(Partner::class);
    }

    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class);
    }

    public function isAdmin(): bool
    {
        return $this->role === 'admin';
    }

    public function isPartner(): bool
    {
        return $this->role === 'partner';
    }

    public function canAccessPanel(Panel $panel): bool
    {
        return match ($panel->getId()) {
            'admin' => $this->isAdmin(),
            'partner' => $this->isPartner() || $this->isAdmin(),
            default => false,
        };
    }

    /** @return Collection<int, Spa> */
    public function getTenants(Panel $panel): Collection
    {
        if ($this->isAdmin()) {
            return Spa::orderBy('name')->get();
        }

        return $this->partner?->spas()->orderBy('name')->get() ?? new Collection;
    }

    public function canAccessTenant(Model $tenant): bool
    {
        return $this->isAdmin() || ($tenant instanceof Spa && $tenant->partner_id === $this->partner?->id);
    }
}
