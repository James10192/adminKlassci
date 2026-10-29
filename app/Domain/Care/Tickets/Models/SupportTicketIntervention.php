<?php

namespace App\Domain\Care\Tickets\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A task for an authorized school operator, NEVER an implied request
 * to the ticket reporter. PENDING_ROUTING has no external side-effects.
 */
class SupportTicketIntervention extends Model
{
    public const PENDING_ROUTING = 'PENDING_ROUTING';
    public const WAITING_ASSIGNEE = 'WAITING_ASSIGNEE';
    public const IN_PROGRESS = 'IN_PROGRESS';
    public const COMPLETED = 'COMPLETED';
    public const CANCELLED = 'CANCELLED';

    protected $table = 'support_ticket_interventions';

    protected $fillable = [
        'tenant_id', 'ticket_id', 'idempotency_key', 'request_hash',
        'title', 'instructions', 'required_permission',
        'target_external_user_id', 'status', 'created_by_admin_id', 'due_at',
    ];

    protected $hidden = ['request_hash', 'idempotency_key'];

    protected function casts(): array
    {
        return [
            'target_external_user_id' => 'integer',
            'due_at' => 'datetime',
            'routed_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(SupportTicket::class, 'ticket_id');
    }
}
