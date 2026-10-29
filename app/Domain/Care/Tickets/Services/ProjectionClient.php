<?php

namespace App\Domain\Care\Tickets\Services;

use App\Domain\Care\Tickets\Enums\TypeActeur;
use App\Domain\Care\Tickets\Enums\VisibiliteMessage;
use App\Domain\Care\Tickets\Enums\StatutTicket;
use App\Domain\Care\Tickets\Enums\StatutClient;
use App\Domain\Care\Tickets\Models\SupportTicket;
use App\Domain\Care\Tickets\Models\SupportTicketAttachment;
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
    public function resume(SupportTicket $ticket, bool $personnelEtablissement = false): array
    {
        // Une demande destinée à la scolarité ne doit pas afficher « action
        // requise de votre part » à l'étudiant à l'origine du ticket.
        $statut = $ticket->status === StatutTicket::WaitingCustomer
            && $ticket->waiting_on === 'school_staff'
            && ! $personnelEtablissement
            ? StatutClient::EnAnalyse : $ticket->status->statutClient();
        $derniere = $ticket->relationLoaded('messagesPublics')
            ? $ticket->messagesPublics->last()
            : $ticket->messagesPublics()->latest('id')->first();

        $messages = $ticket->relationLoaded('messagesPublics') ? $ticket->messagesPublics : null;
        $derniereDuSupport = $messages !== null
            ? $messages->filter(fn ($m) => $m->author_type !== TypeActeur::Client)->last()
            : $ticket->messagesPublics()->where('author_type', '!=', TypeActeur::Client->value)->latest('id')->first();

        $derniereAdministration = $personnelEtablissement
            ? $ticket->messages()->where('visibility', VisibiliteMessage::Administration->value)
                ->where('author_type', '!=', TypeActeur::Client->value)->latest('id')->first()
            : null;

        return [
            'reference' => $ticket->reference,
            'titre' => $ticket->title,
            'categorie' => ['code' => $ticket->customer_category->value, 'libelle' => $ticket->customer_category->libelle()],
            'statut' => ['code' => $statut->value, 'libelle' => $statut->libelle()],
            'rapporteur' => ['id' => $ticket->reporter_external_id, 'nom' => $ticket->reporter_name_snapshot],
            'cree_le' => $ticket->created_at?->toIso8601String(),
            'mis_a_jour_le' => $ticket->updated_at?->toIso8601String(),
            'derniere_reponse' => $derniere ? $this->message($derniere) : null,
            // Ajout v1 : ce que l'ecole compare a sa derniere lecture pour notifier.
            'derniere_reponse_support_le' => $derniereDuSupport?->created_at?->toIso8601String(),
            // Uniquement pour le personnel autorisé (scope=school).
            ...($personnelEtablissement ? [
                'action_cible' => $ticket->waiting_on === 'school_staff' ? 'ETABLISSEMENT' : 'DEMANDEUR',
                'derniere_demande_etablissement_le' => $derniereAdministration?->created_at?->toIso8601String(),
            ] : []),
        ];
    }

    public function detail(SupportTicket $ticket, bool $personnelEtablissement = false): array
    {
        return $this->resume($ticket, $personnelEtablissement) + [
            'description' => $ticket->description,
            'messages' => ($personnelEtablissement ? $ticket->messagesEtablissement : $ticket->messagesPublics)
                ->map(fn ($m) => $this->message($m))->values()->all(),
            'pieces_jointes' => $ticket->piecesJointesPubliques->map(fn ($p) => $this->piece($p))->values()->all(),
        ];
    }

    private function piece(SupportTicketAttachment $p): array
    {
        return [
            'id' => $p->id,
            'nom' => $p->original_name,
            'type' => $p->mime,
            'taille' => $p->size_bytes,
            'auteur' => $p->author_type === TypeActeur::Client ? 'ECOLE' : 'SUPPORT',
            'le' => $p->created_at?->toIso8601String(),
        ];
    }

    private function message(SupportTicketMessage $m): array
    {
        return [
            'auteur' => $m->author_type === TypeActeur::Client ? 'ECOLE' : 'SUPPORT',
            'destinataire' => $m->visibility === VisibiliteMessage::Administration ? 'ETABLISSEMENT' : 'DEMANDEUR',
            'nom' => $m->author_type === TypeActeur::Client ? $m->author_name : 'Support KLASSCI',
            'corps' => $m->body,
            'le' => $m->created_at?->toIso8601String(),
        ];
    }
}
