<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TenantActivityLog extends Model
{
    use HasFactory;

    protected $fillable = [
        'tenant_id',
        'action',
        'description',
        'ip_address',
        'user_agent',
        'performed_by_user_id',
        'metadata',
        'performed_at',
    ];

    protected $casts = [
        'metadata' => 'array',
        'performed_at' => 'datetime',
    ];

    /**
     * Relations
     */
    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }

    public function performedBy()
    {
        return $this->belongsTo(SaasAdmin::class, 'performed_by_user_id');
    }

    /**
     * Scopes
     */
    public function scopeByAction($query, string $action)
    {
        return $query->where('action', $action);
    }

    public function scopeRecent($query, int $days = 7)
    {
        return $query->where('performed_at', '>=', now()->subDays($days));
    }

    public function scopeByPerformer($query, int $userId)
    {
        return $query->where('performed_by_user_id', $userId);
    }

    /**
     * Static helper to log activity
     */
    public static function log(
        int $tenantId,
        string $action,
        string $description,
        ?int $performedByUserId = null,
        array $metadata = []
    ): self {
        return self::create([
            'tenant_id' => $tenantId,
            'action' => $action,
            'description' => $description,
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
            'performed_by_user_id' => $performedByUserId,
            'metadata' => $metadata,
            'performed_at' => now(),
        ]);
    }
}
