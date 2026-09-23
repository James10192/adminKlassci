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
use App\Domain\Care\Tickets\Services\TicketStateMachine;
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

        return DB::transaction(function () use ($ticket, $acteur, $categorie, $severite, $priorite, $domaine, $motif) {
            // Le motif se juge contre l'etat relu sous verrou : une page perimee
            // ne doit pas « poser » une severite qu'un autre agent a deja posee.
            $courant = SupportTicket::whereKey($ticket->getKey())->lockForUpdate()->firstOrFail();

            $changeSeverite = $courant->severity !== null && $courant->severity !== $severite;
            $changePriorite = $courant->priority !== null && $courant->priority !== $priorite;
            if (($changeSeverite || $changePriorite) && ! TicketStateMachine::motifSuffisant($motif)) {
                throw new TransitionRefusee('Modifier une sévérité ou une priorité déjà posée demande un motif d\'au moins '.TicketStateMachine::motifMin().' caractères.');
            }

            $avant = [
                'severite' => $courant->severity?->value,
                'priorite' => $courant->priority?->value,
                'categorie' => $courant->internal_category?->value,
                'domaine' => $courant->product_area,
            ];

            $courant->forceFill([
                'internal_category' => $categorie,
                'severity' => $severite,
                'priority' => $priorite,
                'product_area' => $domaine !== null && trim($domaine) !== '' ? trim($domaine) : null,
            ])->save();

            if ($avant['categorie'] !== $categorie?->value || $avant['domaine'] !== $courant->product_area) {
                $this->journal->consigner($courant, TypeEvenement::Classe, $acteur, $avant['categorie'], $categorie?->value,
                    details: ['domaine' => $courant->product_area]);
            }
            if ($avant['severite'] !== $severite?->value) {
                $this->journal->consigner($courant, TypeEvenement::SeveriteChangee, $acteur, $avant['severite'], $severite?->value, $motif);
            }
            if ($avant['priorite'] !== $priorite?->value) {
                $this->journal->consigner($courant, TypeEvenement::PrioriteChangee, $acteur, $avant['priorite'], $priorite?->value, $motif);
            }

            $ticket->setRawAttributes($courant->getAttributes(), true);

            return $ticket;
        });
    }
}
