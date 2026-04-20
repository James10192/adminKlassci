<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GroupPortalSsoLog extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'group_member_id',
        'tenant_code',
        'user_email_impersonated',
        'redirect_to',
        'ip_address',
        'user_agent',
        'success',
        'error_reason',
    ];

    protected $casts = [
        'success' => 'boolean',
        'created_at' => 'datetime',
    ];

    public function groupMember(): BelongsTo
    {
        return $this->belongsTo(GroupMember::class);
    }
}
