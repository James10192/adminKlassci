<?php

namespace App\Domain\Care\Tickets\Models;

use App\Domain\Care\Tickets\Enums\Canal;
use App\Domain\Care\Tickets\Enums\CategorieClient;
use App\Domain\Care\Tickets\Enums\CategorieInterne;
use App\Domain\Care\Tickets\Enums\Priorite;
use App\Domain\Care\Tickets\Enums\Severite;
use App\Domain\Care\Tickets\Enums\StatutTicket;
use App\Domain\Care\Tickets\Enums\VisibiliteMessage;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Une demande de support : ce qu'une personne d'une ecole a signale.
 *
 * Le statut ne se pose pas a la main : il passe par TicketStateMachine, qui
 * refuse une transition non permise et ecrit le journal. `status` est donc
 * absent de $fillable a dessein.
 */
class SupportTicket extends Model
{
    use SoftDeletes;

    protected $table = 'support_tickets';

    protected $fillable = [
        'tenant_id',
        'idempotency_key',
        'request_hash',
        'reporter_external_id',
        'reporter_name_snapshot',
        'reporter_email_snapshot',
        'reporter_roles_snapshot',
        'channel',
        'customer_category',
        'internal_category',
        'title',
        'description',
        'severity',
        'priority',
        'product_area',
        'assigned_admin_id',
        'is_security_restricted',
    ];

    protected $hidden = ['idempotency_key', 'request_hash'];

    protected function casts(): array
    {
        return [
            'reporter_roles_snapshot' => 'array',
            'channel' => Canal::class,
            'customer_category' => CategorieClient::class,
            'internal_category' => CategorieInterne::class,
            'status' => StatutTicket::class,
            'severity' => Severite::class,
            'priority' => Priorite::class,
            'is_security_restricted' => 'boolean',
            'first_response_at' => 'datetime',
            'triaged_at' => 'datetime',
            'resolved_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_admin_id');
    }

    public function context(): HasOne
    {
        return $this->hasOne(SupportTicketContext::class, 'ticket_id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(SupportTicketMessage::class, 'ticket_id')->orderBy('created_at')->orderBy('id');
    }

    public function messagesPublics(): HasMany
    {
        return $this->messages()->where('visibility', VisibiliteMessage::PublicClient->value);
    }

    public function events(): HasMany
    {
        return $this->hasMany(SupportTicketEvent::class, 'ticket_id')->orderBy('created_at')->orderBy('id');
    }

    public function scopePourInstance(Builder $query, Tenant $tenant): Builder
    {
        return $query->where('tenant_id', $tenant->id);
    }

    public function scopeOuverts(Builder $query): Builder
    {
        return $query->whereIn('status', StatutTicket::valeursOuvertes());
    }
}
