<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;

use App\Enum\User\ManagementRole;
use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Models\Contracts\HasTenants;
use Filament\Panel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Collection;

class User extends Authenticatable implements FilamentUser, HasTenants
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'is_active',
        'is_admin',
        'management_role',
    ];

    protected string $guard_name = 'web';

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'is_active' => 'boolean',
            'is_admin' => 'boolean',
            'management_role' => ManagementRole::class,
            'password' => 'hashed',
        ];
    }

    public function canAccessPanel(Panel $panel): bool
    {
        if (! $this->isActive()) {
            return false;
        }

        if ($panel->getId() === 'management') {
            return $this->canAccessManagementPanel();
        }

        return true;
    }

    public function managementRole(): ?ManagementRole
    {
        if ($this->management_role instanceof ManagementRole) {
            return $this->management_role;
        }

        return $this->is_admin ? ManagementRole::SUPER_ADMIN : null;
    }

    public function isSuperAdmin(): bool
    {
        return $this->managementRole() === ManagementRole::SUPER_ADMIN;
    }

    public function isManagementAdmin(): bool
    {
        return $this->managementRole() === ManagementRole::MANAGEMENT_ADMIN;
    }

    public function isActive(): bool
    {
        return $this->is_active !== false;
    }

    public function canAccessManagementPanel(): bool
    {
        return $this->isActive() && ($this->isSuperAdmin() || $this->isManagementAdmin());
    }

    public function canManageUsers(): bool
    {
        return $this->isActive() && ($this->isSuperAdmin() || $this->isManagementAdmin());
    }

    public function canManageProviders(): bool
    {
        return $this->isActive() && $this->isSuperAdmin();
    }

    public function canManageFiscalSequences(): bool
    {
        return $this->isActive() && $this->isSuperAdmin();
    }

    public function canManageFiscalOperations(): bool
    {
        return $this->isActive() && ($this->isSuperAdmin() || $this->isManagementAdmin());
    }

    public function companies(): BelongsToMany
    {
        return $this->belongsToMany(Company::class);
    }

    public function getTenants(Panel $panel): Collection
    {
        return $this->companies()
            ->where('companies.is_active', true)
            ->wherePivot('is_active', true)
            ->get();
    }

    public function canAccessTenant(Model $tenant): bool
    {
        return $this->isActive()
            && $tenant->is_active !== false
            && $this->companies()
                ->whereKey($tenant)
                ->where('companies.is_active', true)
                ->wherePivot('is_active', true)
                ->exists();
    }

    /**
     * Verifica se o usuário pertence a uma empresa específica.
     */
    public function belongsToCompany(int $companyId): bool
    {
        return $this->isActive()
            && $this->companies()
                ->where('companies.id', $companyId)
                ->where('companies.is_active', true)
                ->wherePivot('is_active', true)
                ->exists();
    }

    public function canViewAuditLogs(): bool
    {
        if ($this->isSuperAdmin()) {
            return true;
        }

        if (method_exists($this, 'hasPermissionTo') && $this->hasPermissionTo('view_audit_logs')) {
            return true;
        }

        return $this->can('view_audit_logs');
    }
}
