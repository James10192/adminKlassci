<?php

namespace App\Domain\Care\Tickets\Actions;

use App\Domain\Care\Tickets\Enums\TypeEvenement;
use App\Domain\Care\Tickets\Models\SupportTicket;
use App\Domain\Care\Tickets\Services\Acteur;
use App\Domain\Care\Tickets\Services\Journal;
use App\Models\User;

class AssignerTicket
{
    public function __construct(private readonly Journal $journal)
    {
    }

    public function executer(SupportTicket $ticket, User $par, ?User $assigne): SupportTicket
    {
        $avant = $ticket->assigned_admin_id;
        if ($avant === $assigne?->id) {
            return $ticket;
        }

        $ticket->forceFill(['assigned_admin_id' => $assigne?->id])->save();
        $this->journal->consigner($ticket, TypeEvenement::Assigne, Acteur::personnel($par),
            $avant !== null ? (string) $avant : null, $assigne?->id !== null ? (string) $assigne->id : null);

        return $ticket;
    }
}
