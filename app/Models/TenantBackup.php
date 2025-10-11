<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TenantBackup extends Model
{
    use HasFactory;

    protected $fillable = [
        'tenant_id',
        'type',
        'backup_path',
        'size_bytes',
        'database_backup_path',
        'storage_backup_path',
        'status',
        'error_message',
        'expires_at',
        'created_by_user_id',
    ];

    protected $casts = [
        'size_bytes' => 'integer',
        'expires_at' => 'datetime',
    ];

    /**
     * Relations
     */
    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }

    public function createdBy()
    {
        return $this->belongsTo(SaasAdmin::class, 'created_by_user_id');
    }

    /**
     * Scopes
     */
    public function scopeCompleted($query)
    {
        return $query->where('status', 'completed');
    }

    public function scopeFailed($query)
    {
        return $query->where('status', 'failed');
    }

    public function scopeFull($query)
    {
        return $query->where('type', 'full');
    }

    public function scopeDatabaseOnly($query)
    {
        return $query->where('type', 'database_only');
    }

    public function scopeNotExpired($query)
    {
        return $query->where(function($q) {
            $q->whereNull('expires_at')
              ->orWhere('expires_at', '>', now());
        });
    }

    /**
     * Accessors
     */
    public function getIsExpiredAttribute(): bool
    {
        return $this->expires_at && $this->expires_at->isPast();
    }

    public function getSizeMbAttribute(): float
    {
        return round($this->size_bytes / 1024 / 1024, 2);
    }

    public function getSizeGbAttribute(): float
    {
        return round($this->size_bytes / 1024 / 1024 / 1024, 2);
    }
}
