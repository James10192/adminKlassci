<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class GroupAlertNotificationLog extends Model
{
    use HasFactory;

    protected $table = 'group_alert_notifications_log';

    protected $fillable = [
        'group_member_id',
        'group_id',
        'tenant_code',
        'alert_type',
        'severity',
        'fingerprint',
        'channel',
        'sent_at',
    ];

    protected $casts = [
        'sent_at' => 'datetime',
    ];

    public function groupMember()
    {
        return $this->belongsTo(GroupMember::class);
    }

    /**
     * Returns true when a notification with the same fingerprint has been
     * sent to this member within the given window. Called by the dispatcher
     * BEFORE dispatch to short-circuit duplicates.
     */
    public static function wasRecentlyNotified(int $groupMemberId, string $fingerprint, int $hours): bool
    {
        return static::query()
            ->where('group_member_id', $groupMemberId)
            ->where('fingerprint', $fingerprint)
            ->where('sent_at', '>=', now()->subHours($hours))
            ->exists();
    }
}
