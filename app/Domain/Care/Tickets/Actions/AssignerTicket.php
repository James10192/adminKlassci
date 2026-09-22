<?php

namespace App\Domain\Care\Tickets\Actions;

use App\Domain\Care\Tickets\Enums\TypeEvenement;
use App\Domain\Care\Tickets\Models\SupportTicket;
use App\Domain\Care\Tickets\Services\Acteur;
use App\Domain\Care\Tickets\Services\Journal;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class AssignerTicket
{
    public function __construct(private readonly Journal $journal)
    {
    }

    public function executer(SupportTicket $ticket, User $par, ?User $assigne): SupportTicket
    {
        return DB::transaction(function () use ($ticket, $par, $assigne) {
            $courant = SupportTicket::whereKey($ticket->getKey())->lockForUpdate()->firstOrFail();
            $avant = $courant->assigned_admin_id;
            if ($avant === $assigne?->id) {
                return $ticket;
            }

            $courant->forceFill(['assigned_admin_id' => $assigne?->id])->save();
            $this->journal->consigner($courant, TypeEvenement::Assigne, Acteur::personnel($par),
                $avant !== null ? (string) $avant : null, $assigne?->id !== null ? (string) $assigne->id : null);

            $ticket->setRawAttributes($courant->getAttributes(), true);

            return $ticket;
        });
    }
}
