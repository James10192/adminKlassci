<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Tenant extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'code',
        'name',
        'subdomain',
        'database_name',
        'database_credentials',
        'git_branch',
        'git_commit_hash',
        'last_deployed_at',
        'status',
        'plan',
        'monthly_fee',
        'subscription_start_date',
        'subscription_end_date',
        'max_users',
        'max_staff',
        'max_students',
        'max_inscriptions_per_year',
        'max_storage_mb',
        'current_users',
        'current_staff',
        'current_students',
        'current_storage_mb',
        'admin_name',
        'admin_email',
        'support_email',
        'phone',
        'address',
        'metadata',
    ];

    protected $casts = [
        'database_credentials' => 'array',
        'metadata' => 'array',
        'last_deployed_at' => 'datetime',
        'subscription_start_date' => 'date',
        'subscription_end_date' => 'date',
        'monthly_fee' => 'decimal:2',
        'max_users' => 'integer',
        'max_staff' => 'integer',
        'max_students' => 'integer',
        'max_inscriptions_per_year' => 'integer',
        'max_storage_mb' => 'integer',
        'current_users' => 'integer',
        'current_staff' => 'integer',
        'current_students' => 'integer',
        'current_storage_mb' => 'integer',
    ];

    /**
     * Relations
     */
    public function deployments()
    {
        return $this->hasMany(TenantDeployment::class);
    }

    public function healthChecks()
    {
        return $this->hasMany(TenantHealthCheck::class);
    }

    public function backups()
    {
        return $this->hasMany(TenantBackup::class);
    }

    public function features()
    {
        return $this->hasMany(TenantFeature::class);
    }

    public function activityLogs()
    {
        return $this->hasMany(TenantActivityLog::class);
    }

    public function invoices()
    {
        return $this->hasMany(Invoice::class);
    }

    /**
     * Scopes
     */
    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    public function scopeSuspended($query)
    {
        return $query->where('status', 'suspended');
    }

    public function scopeByPlan($query, string $plan)
    {
        return $query->where('plan', $plan);
    }

    /**
     * Accessors & Mutators
     */
    public function getFullUrlAttribute(): string
    {
        return "https://{$this->subdomain}.klassci.com";
    }

    public function getIsActiveAttribute(): bool
    {
        return $this->status === 'active';
    }

    public function getIsExpiredAttribute(): bool
    {
        return $this->subscription_end_date && $this->subscription_end_date->isPast();
    }

    /**
     * Helper methods
     */
    public function hasFeature(string $featureKey): bool
    {
        return $this->features()
            ->where('feature_key', $featureKey)
            ->where('is_enabled', true)
            ->exists();
    }

    public function isOverLimit(string $limitType): bool
    {
        return match($limitType) {
            'users' => $this->current_users >= $this->max_users,
            'staff' => $this->current_staff >= $this->max_staff,
            'students' => $this->current_students >= $this->max_students,
            'storage' => $this->current_storage_mb >= $this->max_storage_mb,
            default => false,
        };
    }

    public function isOverQuota(): bool
    {
        return $this->current_users > $this->max_users
            || $this->current_staff > $this->max_staff
            || $this->current_students > $this->max_students
            || $this->current_storage_mb > $this->max_storage_mb;
    }
}
