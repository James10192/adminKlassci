<?php

namespace App\Models;

use App\Enums\GroupMemberRole;
use Filament\Models\Contracts\FilamentUser;
use Filament\Models\Contracts\HasName;
use Filament\Panel;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Hash;

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

    public function mustChangePassword(): bool
    {
        return $this->password_changed_at === null;
    }

    public function roleEnum(): ?GroupMemberRole
    {
        return GroupMemberRole::tryFrom((string) $this->role);
    }

    /**
     * Writes a newly minted temporary password + invitation token hash,
     * leaving `password_changed_at` null so EnsurePasswordChanged traps
     * the next login. Returns the model for fluent use.
     */
    public function assignInvitationCredentials(string $plainPassword, string $tokenHash): self
    {
        $this->forceFill([
            'password' => Hash::make($plainPassword),
            'password_changed_at' => null,
            'invitation_token' => $tokenHash,
            'invitation_sent_at' => now(),
        ])->save();

        return $this;
    }

    public function recordPasswordRotation(string $plainPassword): self
    {
        $this->forceFill([
            'password' => Hash::make($plainPassword),
            'password_changed_at' => now(),
            'invitation_token' => null,
        ])->save();

        return $this;
    }

    /**
     * Used by the admin-side reset command when a username-only member
     * needs a new temp password delivered out-of-band. Mirrors invitation
     * state (password_changed_at null) but clears the URL token since no
     * new signed URL is issued.
     */
    public function resetToTemporaryPassword(string $plainPassword): self
    {
        $this->forceFill([
            'password' => Hash::make($plainPassword),
            'password_changed_at' => null,
            'invitation_token' => null,
        ])->save();

        return $this;
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

    public function getGroupTenants()
    {
        return $this->group->tenants()->active()->get();
    }
}
