<?php

namespace App\Domain\Care\Tickets\Actions;

use App\Domain\Care\Tickets\Enums\TypeEvenement;
use App\Domain\Care\Tickets\Enums\VisibiliteMessage;
use App\Domain\Care\Tickets\Exceptions\CleIdempotenceReutilisee;
use App\Domain\Care\Tickets\Exceptions\DemandeClose;
use App\Domain\Care\Tickets\Models\SupportTicket;
use App\Domain\Care\Tickets\Services\Acteur;
use App\Domain\Care\Tickets\Services\Journal;
use App\Domain\Care\Tickets\Services\TicketStateMachine;
use Illuminate\Support\Facades\DB;

/**
 * L'ecole repond sur sa demande.
 *
 * Repondre, c'est rendre la main au support : une demande qui attendait l'ecole
 * repart en attente support, une demande resolue se rouvre. Une demande close,
 * rejetee ou fusionnee ne se rouvre pas par un message : l'ecole en signale une
 * nouvelle, que le support peut rattacher.
 *
 * Idempotente par la cle de l'envoi : un renvoi apres une reponse perdue
 * retrouve son message. Rend true si c'etait un renvoi.
 */
class RepondreParLEcole
{
    public function __construct(
        private readonly Journal $journal,
        private readonly TicketStateMachine $etats,
    ) {
    }

    public function executer(SupportTicket $ticket, int $rapporteur, ?string $nom, string $corps, string $cle): bool
    {
        $corps = trim($corps);

        return DB::transaction(function () use ($ticket, $rapporteur, $nom, $corps, $cle) {
            $courant = SupportTicket::whereKey($ticket->getKey())->lockForUpdate()->firstOrFail();

            $existant = $courant->messages()->where('client_key', $cle)->first();
            if ($existant !== null) {
                if ($existant->body !== $corps) {
                    throw new CleIdempotenceReutilisee('Cette clé a déjà servi pour un autre message.');
                }

                return true;
            }

            if (TicketStateMachine::fermeeALEcole($courant->status)) {
                throw new DemandeClose('Cette demande est close. Signalez un nouveau problème si besoin.');
            }

            $acteur = Acteur::client($rapporteur, $nom);
            $message = $courant->messages()->create([
                'author_type' => $acteur->type,
                'author_ref' => $acteur->reference,
                'author_name' => $nom,
                'visibility' => VisibiliteMessage::PublicClient,
                'body' => $corps,
                'client_key' => $cle,
            ]);
            $this->journal->consigner($courant, TypeEvenement::ReponseClient, $acteur, details: ['message_id' => $message->id]);

            $this->etats->rendreLaMainAuSupport($courant, $acteur);

            $ticket->setRawAttributes($courant->getAttributes(), true);

            return false;
        });
    }
}
