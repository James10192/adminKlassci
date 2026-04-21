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
        'subscription_plan_id',
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
        'current_inscriptions_per_year',
        'current_storage_mb',
        'admin_name',
        'admin_email',
        'support_email',
        'phone',
        'address',
        'metadata',
        'group_id',
        'api_token',
        'api_token_created_at',
    ];

    protected $casts = [
        'database_credentials' => 'array',
        'metadata' => 'array',
        'last_deployed_at' => 'datetime',
        'subscription_start_date' => 'date',
        'subscription_end_date' => 'date',
        'api_token_created_at' => 'datetime',
        'monthly_fee' => 'decimal:2',
        'max_users' => 'integer',
        'max_staff' => 'integer',
        'max_students' => 'integer',
        'max_inscriptions_per_year' => 'integer',
        'max_storage_mb' => 'integer',
        'current_users' => 'integer',
        'current_staff' => 'integer',
        'current_students' => 'integer',
        'current_inscriptions_per_year' => 'integer',
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

    /**
     * Latest health check across all check types — used by the group portal
     * stale-tenant detection (tenant hasn't checked in recently OR is flagged
     * unhealthy). latestOfMany() avoids the per-tenant subquery that naive
     * "$tenant->healthChecks->first()" would trigger on a group dashboard.
     */
    public function latestHealthCheck()
    {
        return $this->hasOne(TenantHealthCheck::class)->latestOfMany('checked_at');
    }

    /**
     * Latest ssl_certificate health check specifically. Filtered aggregate so
     * PR7b SSL expiry alerts can read `metadata.days_remaining` directly
     * without scanning the full health_checks history.
     */
    public function latestSslHealthCheck()
    {
        return $this->hasOne(TenantHealthCheck::class)->ofMany(
            ['checked_at' => 'max'],
            fn ($query) => $query->where('check_type', 'ssl_certificate')
        );
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

    public function subscriptionPlan()
    {
        return $this->belongsTo(SubscriptionPlan::class);
    }

    public function group()
    {
        return $this->belongsTo(Group::class);
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
        $days = $this->daysRemaining();

        return $days !== null && $days < 0;
    }

    /**
     * Days remaining on the subscription; null when there is no end date
     * (free tier or missing data), negative when already expired. The
     * startOfDay() calls are defensive — the `date` cast already strips
     * time, but this keeps behaviour stable if the cast ever changes.
     */
    public function daysRemaining(): ?int
    {
        if (! $this->subscription_end_date) {
            return null;
        }

        return (int) now()->startOfDay()->diffInDays(
            $this->subscription_end_date->startOfDay(),
            false
        );
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
            'inscriptions' => $this->current_inscriptions_per_year >= $this->max_inscriptions_per_year,
            'storage' => $this->current_storage_mb >= $this->max_storage_mb,
            default => false,
        };
    }

    public function isOverQuota(): bool
    {
        return $this->current_users > $this->max_users
            || $this->current_staff > $this->max_staff
            || $this->current_students > $this->max_students
            || $this->current_inscriptions_per_year > $this->max_inscriptions_per_year
            || $this->current_storage_mb > $this->max_storage_mb;
    }
}
