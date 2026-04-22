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
        'password',
        'role',
        'phone',
        'avatar_path',
        'is_active',
        'last_login_at',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected $casts = [
        'email_verified_at' => 'datetime',
        'last_login_at' => 'datetime',
        'is_active' => 'boolean',
        'password' => 'hashed',
    ];

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
