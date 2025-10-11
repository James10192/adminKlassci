<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class SaasAdmin extends Authenticatable
{
    use HasFactory, Notifiable, SoftDeletes;

    protected $fillable = [
        'name',
        'email',
        'password',
        'role',
        'is_active',
        'phone',
        'avatar',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected $casts = [
        'email_verified_at' => 'datetime',
        'is_active' => 'boolean',
        'password' => 'hashed',
    ];

    /**
     * Relations
     */
    public function deployments()
    {
        return $this->hasMany(TenantDeployment::class, 'deployed_by_user_id');
    }

    public function backupsCreated()
    {
        return $this->hasMany(TenantBackup::class, 'created_by_user_id');
    }

    public function activityLogs()
    {
        return $this->hasMany(TenantActivityLog::class, 'performed_by_user_id');
    }

    /**
     * Scopes
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeSuperAdmins($query)
    {
        return $query->where('role', 'super_admin');
    }

    public function scopeSupport($query)
    {
        return $query->where('role', 'support');
    }

    public function scopeBilling($query)
    {
        return $query->where('role', 'billing');
    }

    /**
     * Accessors
     */
    public function getIsSuperAdminAttribute(): bool
    {
        return $this->role === 'super_admin';
    }

    public function getIsSupportAttribute(): bool
    {
        return $this->role === 'support';
    }

    public function getIsBillingAttribute(): bool
    {
        return $this->role === 'billing';
    }

    /**
     * Permission checks
     */
    public function canManageTenants(): bool
    {
        return in_array($this->role, ['super_admin', 'support']);
    }

    public function canDeploy(): bool
    {
        return in_array($this->role, ['super_admin', 'support']);
    }

    public function canManageBilling(): bool
    {
        return in_array($this->role, ['super_admin', 'billing']);
    }

    public function canViewReports(): bool
    {
        return $this->role === 'super_admin';
    }
}
