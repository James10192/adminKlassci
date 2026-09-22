<?php

namespace App\Domain\Care\Tickets\Services;

use App\Domain\Care\Tickets\Enums\TypeEvenement;
use App\Domain\Care\Tickets\Models\SupportTicket;
use App\Domain\Care\Tickets\Models\SupportTicketEvent;

/** Le seul point d'ecriture du journal d'une demande. */
class Journal
{
    public function consigner(
        SupportTicket $ticket,
        TypeEvenement $type,
        Acteur $acteur,
        ?string $de = null,
        ?string $vers = null,
        ?string $raison = null,
        array $details = [],
    ): SupportTicketEvent {
        return SupportTicketEvent::create([
            'ticket_id' => $ticket->id,
            'type' => $type,
            'actor_type' => $acteur->type,
            'actor_ref' => $acteur->reference,
            'from_value' => $de,
            'to_value' => $vers,
            'reason' => $raison,
            'payload' => $details ?: null,
        ]);
    }
}
