<?php

namespace App\Models;

use App\Enums\AlertType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class GroupMemberNotificationPreference extends Model
{
    use HasFactory;

    protected $fillable = [
        'group_member_id',
        'email_enabled',
        'immediate_critical',
        'daily_digest_warnings',
        'digest_time',
        'dedup_hours',
        'last_digest_sent_at',
        'disabled_alert_types',
    ];

    protected $casts = [
        'email_enabled' => 'boolean',
        'immediate_critical' => 'boolean',
        'daily_digest_warnings' => 'boolean',
        'dedup_hours' => 'integer',
        'last_digest_sent_at' => 'datetime',
        'disabled_alert_types' => 'array',
    ];

    public function groupMember()
    {
        return $this->belongsTo(GroupMember::class);
    }

    /**
     * Lazy getter used by AlertNotificationDispatcher — creates the row with
     * defaults on first access so members get sensible out-of-box behavior
     * without a seeder or UI prerequisite. Defaults are duplicated here (vs
     * relying on the migration column defaults) so the in-memory model
     * returned post-insert already has them populated — Eloquent doesn't
     * refetch after firstOrCreate.
     */
    public static function forMember(GroupMember $member): self
    {
        return static::firstOrCreate(
            ['group_member_id' => $member->id],
            [
                'email_enabled' => true,
                'immediate_critical' => true,
                'daily_digest_warnings' => true,
                'digest_time' => '08:00',
                'dedup_hours' => 24,
            ],
        );
    }

    /**
     * True when the member has opted in for emails AND this specific
     * AlertType isn't in their disabled list.
     */
    public function acceptsAlertType(AlertType $type): bool
    {
        if (! $this->email_enabled) {
            return false;
        }

        $disabled = (array) ($this->disabled_alert_types ?? []);

        return ! in_array($type->value, $disabled, true);
    }
}
