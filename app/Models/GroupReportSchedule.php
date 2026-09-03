<?php

namespace App\Models;

use App\Services\Group\ScheduleDueResolver;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Une programmation d'envoi de rapport pour un groupe.
 *
 * Les destinataires sont des membres du groupe, jamais des adresses libres :
 * un état financier consolidé ne doit pas pouvoir partir vers une adresse
 * extérieure depuis le portail.
 */
class GroupReportSchedule extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'group_id', 'report_key', 'frequency',
        'day_of_week', 'day_of_month', 'hour',
        'recipient_member_ids', 'is_active',
        'last_sent_at', 'last_error', 'created_by',
    ];

    protected $casts = [
        'recipient_member_ids' => 'array',
        'is_active' => 'boolean',
        'day_of_week' => 'integer',
        'day_of_month' => 'integer',
        'hour' => 'integer',
        'last_sent_at' => 'immutable_datetime',
    ];

    public function group()
    {
        return $this->belongsTo(Group::class);
    }

    /** @param  Builder<self>  $query */
    public function scopeActives(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Destinataires réels : membres encore présents, actifs, et qui ont une
     * adresse. Un membre supprimé ou desactivé disparaît de l'envoi sans
     * qu'on ait à toucher la programmation.
     *
     * @return \Illuminate\Support\Collection<int, GroupMember>
     */
    public function destinataires()
    {
        $ids = $this->recipient_member_ids ?: [];

        if (empty($ids)) {
            return collect();
        }

        return GroupMember::query()
            ->whereIn('id', $ids)
            ->where('group_id', $this->group_id)
            ->where('is_active', true)
            ->whereNotNull('email')
            ->get();
    }

    public function estDue(ScheduleDueResolver $resolver, \Carbon\CarbonImmutable $maintenant): bool
    {
        return $resolver->estDue(
            $this->frequency,
            $this->day_of_week,
            $this->day_of_month,
            (int) $this->hour,
            $this->last_sent_at,
            $maintenant,
        );
    }
}
