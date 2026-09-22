<?php

namespace App\Domain\Care\Tickets\Services;

use App\Domain\Care\Tickets\Enums\TypeActeur;
use App\Domain\Care\Tickets\Models\SupportTicket;
use App\Domain\Care\Tickets\Models\SupportTicketMessage;

/**
 * Ce qu'une ecole a le droit de lire d'une demande. La seule sortie vers les
 * instances.
 *
 * Ecrite en liste d'inclusion, pas d'exclusion : un champ ajoute demain au
 * modele (une analyse, une note, un lien GitHub) ne sort pas tant que
 * quelqu'un ne l'a pas ajoute ICI. Les messages sont filtres sur
 * PUBLIC_TO_CUSTOMER ; le statut sort dans sa forme client, jamais interne.
 */
class ProjectionClient
{
    public function resume(SupportTicket $ticket): array
    {
        $statut = $ticket->status->statutClient();
        $derniere = $ticket->relationLoaded('messagesPublics')
            ? $ticket->messagesPublics->last()
            : $ticket->messagesPublics()->latest('id')->first();

        return [
            'reference' => $ticket->reference,
            'titre' => $ticket->title,
            'categorie' => ['code' => $ticket->customer_category->value, 'libelle' => $ticket->customer_category->libelle()],
            'statut' => ['code' => $statut->value, 'libelle' => $statut->libelle()],
            'rapporteur' => ['id' => $ticket->reporter_external_id, 'nom' => $ticket->reporter_name_snapshot],
            'cree_le' => $ticket->created_at?->toIso8601String(),
            'mis_a_jour_le' => $ticket->updated_at?->toIso8601String(),
            'derniere_reponse' => $derniere ? $this->message($derniere) : null,
        ];
    }

    public function detail(SupportTicket $ticket): array
    {
        return $this->resume($ticket) + [
            'description' => $ticket->description,
            'messages' => $ticket->messagesPublics->map(fn ($m) => $this->message($m))->values()->all(),
        ];
    }

    private function message(SupportTicketMessage $m): array
    {
        return [
            'auteur' => $m->author_type === TypeActeur::Client ? 'ECOLE' : 'SUPPORT',
            'nom' => $m->author_type === TypeActeur::Client ? $m->author_name : 'Support KLASSCI',
            'corps' => $m->body,
            'le' => $m->created_at?->toIso8601String(),
        ];
    }
}
