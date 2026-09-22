<?php

namespace App\Domain\Care\Tickets\Actions;

use App\Domain\Care\Tickets\Enums\TypeEvenement;
use App\Domain\Care\Tickets\Enums\VisibiliteMessage;
use App\Domain\Care\Tickets\Models\SupportTicket;
use App\Domain\Care\Tickets\Models\SupportTicketMessage;
use App\Domain\Care\Tickets\Services\Acteur;
use App\Domain\Care\Tickets\Services\Journal;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Un message du personnel sur une demande.
 *
 * La visibilite est un parametre obligatoire, sans valeur par defaut : le
 * formulaire oblige a la choisir, et cette action aussi.
 */
class RepondreTicket
{
    public function __construct(private readonly Journal $journal)
    {
    }

    public function executer(SupportTicket $ticket, User $auteur, string $corps, VisibiliteMessage $visibilite): SupportTicketMessage
    {
        $corps = trim($corps);
        if ($corps === '') {
            throw new \InvalidArgumentException('Le message est vide.');
        }

        return DB::transaction(function () use ($ticket, $auteur, $corps, $visibilite) {
            $acteur = Acteur::personnel($auteur);

            $message = $ticket->messages()->create([
                'author_type' => $acteur->type,
                'author_ref' => $acteur->reference,
                'author_name' => $auteur->name,
                'visibility' => $visibilite,
                'body' => $corps,
            ]);

            $public = $visibilite === VisibiliteMessage::PublicClient;
            if ($public && $ticket->first_response_at === null) {
                $ticket->forceFill(['first_response_at' => now()])->save();
            }

            $this->journal->consigner(
                $ticket,
                $public ? TypeEvenement::ReponseSupport : TypeEvenement::NoteInterne,
                $acteur,
                vers: $visibilite->value,
                details: ['message_id' => $message->id],
            );

            return $message;
        });
    }
}
