<?php

namespace App\Domain\Care\Tickets\Models;

use App\Domain\Care\Tickets\Enums\TypeActeur;
use App\Domain\Care\Tickets\Enums\TypeEvenement;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Une ligne du journal d'une demande. Immuable.
 *
 * Le journal est ce qui permet de repondre, trois mois plus tard, a « qui a
 * change la severite, et pourquoi ». Une ligne qu'on peut reecrire ne repond
 * plus a rien : le modele refuse donc toute modification et toute suppression.
 * La suppression en cascade par la base, elle, reste possible — elle ne suit
 * que la suppression definitive d'une demande, jamais son archivage.
 */
class SupportTicketEvent extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'support_ticket_events';

    protected $fillable = ['ticket_id', 'type', 'actor_type', 'actor_ref', 'from_value', 'to_value', 'reason', 'payload'];

    protected function casts(): array
    {
        return [
            'type' => TypeEvenement::class,
            'actor_type' => TypeActeur::class,
            'payload' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Le journal d\'une demande est immuable.'));
        static::deleting(fn () => throw new LogicException('Le journal d\'une demande est immuable.'));
    }

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(SupportTicket::class, 'ticket_id');
    }
}
