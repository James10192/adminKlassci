<?php

namespace App\Domain\Care\Tickets\Actions;

use App\Domain\Care\Tickets\Enums\TypeEvenement;
use App\Domain\Care\Tickets\Exceptions\TransitionRefusee;
use App\Domain\Care\Tickets\Models\SupportTicket;
use App\Domain\Care\Tickets\Services\Acteur;
use App\Domain\Care\Tickets\Services\Journal;
use App\Domain\Care\Tickets\Services\TicketStateMachine;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Pose ou leve la restriction « securite » d'une demande.
 *
 * Restreinte, une demande disparait de la file et de l'API de l'ecole pour qui
 * n'a pas la capacite support.security.view. Le geste est rare et se justifie :
 * motif ecrit dans les deux sens.
 */
class RestreindreTicket
{
    public function __construct(private readonly Journal $journal)
    {
    }

    public function executer(SupportTicket $ticket, User $par, bool $restreindre, ?string $motif): SupportTicket
    {
        $motif = $motif !== null ? trim($motif) : null;
        if (! TicketStateMachine::motifSuffisant($motif)) {
            throw new TransitionRefusee('Un motif d\'au moins '.TicketStateMachine::motifMin().' caractères est requis.');
        }

        return DB::transaction(function () use ($ticket, $par, $restreindre, $motif) {
            $courant = SupportTicket::whereKey($ticket->getKey())->lockForUpdate()->firstOrFail();
            if ($courant->is_security_restricted === $restreindre) {
                return $ticket;
            }

            $courant->forceFill(['is_security_restricted' => $restreindre])->save();
            $this->journal->consigner($courant, TypeEvenement::RestrictionSecurite, Acteur::personnel($par),
                $restreindre ? 'OUVERTE' : 'RESTREINTE', $restreindre ? 'RESTREINTE' : 'OUVERTE', $motif);

            $ticket->setRawAttributes($courant->getAttributes(), true);

            return $ticket;
        });
    }
}
