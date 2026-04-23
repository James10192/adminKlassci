<?php

namespace App\Models;

use Filament\Models\Contracts\FilamentUser;
use Filament\Models\Contracts\HasName;
use Filament\Panel;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class GroupMember extends Authenticatable implements FilamentUser, HasName
{
    use Notifiable;

    protected $fillable = [
        'group_id',
        'name',
        'email',
        'username',
        'password',
        'role',
        'phone',
        'avatar_path',
        'is_active',
        'last_login_at',
        'password_changed_at',
        'invitation_token',
        'invitation_sent_at',
    ];

    protected $hidden = [
        'password',
        'remember_token',
        'invitation_token',
    ];

    protected $casts = [
        'email_verified_at' => 'datetime',
        'last_login_at' => 'datetime',
        'is_active' => 'boolean',
        'password' => 'hashed',
        'password_changed_at' => 'datetime',
        'invitation_sent_at' => 'datetime',
    ];

    /**
     * True when the member still needs to change their password before
     * using the portal. New invitations land here until the user completes
     * the set-password flow.
     */
    public function mustChangePassword(): bool
    {
        return $this->password_changed_at === null;
    }

    public function group()
    {
        return $this->belongsTo(Group::class);
    }

    public function canAccessPanel(Panel $panel): bool
    {
        return $this->is_active && $panel->getId() === 'group';
    }

    public function getFilamentName(): string
    {
        return $this->name;
    }

    public function isFondateur(): bool
    {
        return $this->role === 'fondateur';
    }

    public function isDirecteurGeneral(): bool
    {
        return $this->role === 'directeur_general';
    }

    public function isDirecteurGeneralAdjoint(): bool
    {
        return $this->role === 'directeur_general_adjoint';
    }

    public function getGroupTenants()
    {
        return $this->group->tenants()->active()->get();
    }
}
