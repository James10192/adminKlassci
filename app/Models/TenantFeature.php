<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TenantFeature extends Model
{
    use HasFactory;

    protected $fillable = [
        'tenant_id',
        'feature_key',
        'is_enabled',
        'config',
    ];

    protected $casts = [
        'is_enabled' => 'boolean',
        'config' => 'array',
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
    public function scopeEnabled($query)
    {
        return $query->where('is_enabled', true);
    }

    public function scopeDisabled($query)
    {
        return $query->where('is_enabled', false);
    }

    public function scopeByFeature($query, string $featureKey)
    {
        return $query->where('feature_key', $featureKey);
    }

    /**
     * Helper methods
     */
    public function enable(): bool
    {
        return $this->update(['is_enabled' => true]);
    }

    public function disable(): bool
    {
        return $this->update(['is_enabled' => false]);
    }

    public function toggle(): bool
    {
        return $this->update(['is_enabled' => !$this->is_enabled]);
    }
}
