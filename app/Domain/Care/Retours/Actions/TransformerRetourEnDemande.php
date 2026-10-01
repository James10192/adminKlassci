<?php

namespace App\Domain\Care\Retours\Actions;

use App\Domain\Care\Retours\Models\RetourAssistant;
use App\Domain\Care\Tickets\Actions\CreerTicket;
use App\Domain\Care\Tickets\DTO\SoumissionTicket;
use App\Domain\Care\Tickets\Enums\CategorieClient;
use App\Domain\Care\Tickets\Models\SupportTicket;
use App\Models\User;

/**
 * Fait d'un 👎 une demande de support, pour la suivre dans la file.
 *
 * Passe par CreerTicket, donc par le même journal, la même machine à états et
 * la même annonce Slack qu'une demande venue de l'école. La demande est posée
 * au nom de la personne qui a donné l'avis : elle la verra dans « Mes
 * demandes » et recevra la réponse du support, ce qui est le but.
 *
 * Idempotent par retour : la clé dérive de son identifiant, un double clic
 * retrouve la demande déjà créée.
 */
class TransformerRetourEnDemande
{
    public function __construct(
        private readonly CreerTicket $creer,
        private readonly TraiterRetourAssistant $traiter,
    ) {
    }

    public function executer(RetourAssistant $retour, User $par): SupportTicket
    {
        if ($retour->ticket) {
            return $retour->ticket;
        }

        $resultat = $this->creer->executer($retour->tenant, $this->soumission($retour), 'retour-assistant-'.$retour->id);

        $retour->forceFill(['support_ticket_id' => $resultat->ticket->id])->save();
        $this->traiter->executer($retour, $par, "Transformé en demande {$resultat->ticket->reference}.");

        return $resultat->ticket;
    }

    private function soumission(RetourAssistant $r): SoumissionTicket
    {
        $max = (int) config('care.limites.description_max');
        $morceaux = array_filter([
            "Retour sur une réponse de l'assistant Nanan (".$r->avis->libelle().($r->raisonLibelle() ? ' : '.$r->raisonLibelle() : '').').',
            $r->commentaire ? "Commentaire : {$r->commentaire}" : null,
            "Question : {$r->question}",
            "Réponse de l'assistant : {$r->reponse}",
        ]);
        $description = mb_substr(implode("\n\n", $morceaux), 0, $max);

        return new SoumissionTicket(
            categorie: $r->raison === 'faux' ? CategorieClient::InformationIncorrecte : CategorieClient::Probleme,
            description: $description,
            titre: "Réponse de l'assistant jugée ".mb_strtolower($r->avis->libelle()),
            rapporteurId: (int) $r->utilisateur_id_externe,
            rapporteurNom: $r->utilisateur_nom,
            rapporteurEmail: null,
            rolesRapporteur: array_values(array_filter([$r->utilisateur_role])),
            contexte: array_filter([
                'url_path' => $r->page,
                'module' => 'assistant',
                'extras' => ['composant' => 'assistant'],
            ]),
        );
    }
}
