<?php

namespace App\Domain\Care\Retours\Actions;

use App\Domain\Care\Retours\Models\RetourAssistant;
use App\Domain\Care\Tickets\Actions\CreerTicket;
use App\Domain\Care\Tickets\DTO\SoumissionTicket;
use App\Domain\Care\Tickets\Enums\CategorieClient;
use App\Domain\Care\Tickets\Models\SupportTicket;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Fait d'un 👎 une demande de support, pour la suivre dans la file.
 *
 * Passe par CreerTicket, donc par le même journal, la même machine à états et
 * la même annonce Slack qu'une demande venue de l'école. La demande est posée
 * au nom de la personne qui a donné l'avis : elle la verra dans « Mes
 * demandes » et recevra la réponse du support, ce qui est le but.
 *
 * Une seule transaction, retour verrouillé : la demande et son lien sur le
 * retour sont écrits ensemble ou pas du tout. Un double clic attend le
 * premier, puis retrouve la demande par le lien. Il n'y a donc jamais de
 * demande orpheline qu'un second essai, avec une description changée,
 * heurterait en « clé d'idempotence réutilisée ».
 *
 * La note interne du retour n'est pas touchée : la référence vit dans
 * support_ticket_id.
 */
class TransformerRetourEnDemande
{
    public function __construct(
        private readonly CreerTicket $creer,
    ) {
    }

    public function executer(RetourAssistant $retour, User $par): SupportTicket
    {
        return DB::transaction(function () use ($retour, $par) {
            $courant = RetourAssistant::whereKey($retour->getKey())->lockForUpdate()->firstOrFail();
            if ($courant->support_ticket_id !== null) {
                return SupportTicket::findOrFail($courant->support_ticket_id);
            }

            $ticket = $this->creer->executer($courant->tenant, $this->soumission($courant), 'retour-assistant-'.$courant->id)->ticket;

            $courant->forceFill([
                'support_ticket_id' => $ticket->id,
                'traite_le' => $courant->traite_le ?? now(),
                'traite_par' => $courant->traite_par ?? $par->id,
            ])->save();
            $retour->setRawAttributes($courant->getAttributes(), true);

            return $ticket;
        }, 3);
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
            categorie: CategorieClient::tryFrom((string) config('care.retours_assistant.categorie_par_raison.'.$r->raison, ''))
                ?? CategorieClient::Probleme,
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
