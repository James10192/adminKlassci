<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Evenement technique reduit a des metadonnees autorisees.
 * Aucun SQL, corps de requete, trace, jeton ou donnee etudiante.
 */
class TenantErrorEvent extends Model
{
    protected $fillable = [
        'tenant_id', 'request_id', 'fingerprint', 'route_name', 'exception_class',
        'category', 'method', 'http_status', 'sqlstate', 'db_errno', 'occurred_at',
    ];

    protected function casts(): array
    {
        return [
            'occurred_at' => 'datetime',
            'resolved_at' => 'datetime',
            'http_status' => 'integer',
            'db_errno' => 'integer',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function resolvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }
}
