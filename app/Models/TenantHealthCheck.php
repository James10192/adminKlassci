<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TenantHealthCheck extends Model
{
    use HasFactory;

    protected $fillable = [
        'tenant_id',
        'check_type',
        'status',
        'response_time_ms',
        'details',
        'metadata',
        'checked_at',
    ];

    protected $casts = [
        'metadata' => 'array',
        'checked_at' => 'datetime',
        'response_time_ms' => 'integer',
    ];

    /**
     * Relations
     */
    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * Scopes
     */
    public function scopeHealthy($query)
    {
        return $query->where('status', 'healthy');
    }

    public function scopeUnhealthy($query)
    {
        return $query->where('status', 'unhealthy');
    }

    public function scopeDegraded($query)
    {
        return $query->where('status', 'degraded');
    }

    public function scopeByType($query, string $type)
    {
        return $query->where('check_type', $type);
    }

    public function scopeRecent($query, int $minutes = 30)
    {
        return $query->where('checked_at', '>=', now()->subMinutes($minutes));
    }

    /**
     * Accessors
     */
    public function getIsHealthyAttribute(): bool
    {
        return $this->status === 'healthy';
    }

    public function getIsUnhealthyAttribute(): bool
    {
        return $this->status === 'unhealthy';
    }
}
