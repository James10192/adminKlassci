<?php

namespace App\Domain\Care\Tickets\Actions;

use App\Domain\Care\Tickets\Enums\CategorieInterne;
use App\Domain\Care\Tickets\Enums\Priorite;
use App\Domain\Care\Tickets\Enums\Severite;
use App\Domain\Care\Tickets\Enums\TypeEvenement;
use App\Domain\Care\Tickets\Exceptions\TransitionRefusee;
use App\Domain\Care\Tickets\Models\SupportTicket;
use App\Domain\Care\Tickets\Services\Acteur;
use App\Domain\Care\Tickets\Services\Journal;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Qualification interne d'une demande.
 *
 * Poser une severite ou une priorite la premiere fois ne demande rien. La
 * CHANGER demande un motif : c'est la question qu'on se posera en relisant
 * l'historique d'un incident.
 */
class ClasserTicket
{
    public function __construct(private readonly Journal $journal)
    {
    }

    public function executer(
        SupportTicket $ticket,
        User $auteur,
        ?CategorieInterne $categorie,
        ?Severite $severite,
        ?Priorite $priorite,
        ?string $domaine,
        ?string $motif = null,
    ): SupportTicket {
        $acteur = Acteur::personnel($auteur);
        $motif = $motif !== null ? trim($motif) : null;

        $changeSeverite = $ticket->severity !== null && $ticket->severity !== $severite;
        $changePriorite = $ticket->priority !== null && $ticket->priority !== $priorite;
        if (($changeSeverite || $changePriorite) && ($motif === null || mb_strlen($motif) < 10)) {
            throw new TransitionRefusee('Modifier une sévérité ou une priorité déjà posée demande un motif d\'au moins 10 caractères.');
        }

        return DB::transaction(function () use ($ticket, $acteur, $categorie, $severite, $priorite, $domaine, $motif) {
            $avant = [
                'severite' => $ticket->severity?->value,
                'priorite' => $ticket->priority?->value,
                'categorie' => $ticket->internal_category?->value,
                'domaine' => $ticket->product_area,
            ];

            $ticket->forceFill([
                'internal_category' => $categorie,
                'severity' => $severite,
                'priority' => $priorite,
                'product_area' => $domaine !== null && trim($domaine) !== '' ? trim($domaine) : null,
            ])->save();

            if ($avant['categorie'] !== $categorie?->value || $avant['domaine'] !== $ticket->product_area) {
                $this->journal->consigner($ticket, TypeEvenement::Classe, $acteur, $avant['categorie'], $categorie?->value,
                    details: ['domaine' => $ticket->product_area]);
            }
            if ($avant['severite'] !== $severite?->value) {
                $this->journal->consigner($ticket, TypeEvenement::SeveriteChangee, $acteur, $avant['severite'], $severite?->value, $motif);
            }
            if ($avant['priorite'] !== $priorite?->value) {
                $this->journal->consigner($ticket, TypeEvenement::PrioriteChangee, $acteur, $avant['priorite'], $priorite?->value, $motif);
            }

            return $ticket;
        });
    }
}
