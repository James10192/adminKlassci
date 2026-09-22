<?php

namespace App\Domain\Care\Tickets\Services;

use App\Domain\Care\Tickets\Enums\StatutTicket as S;
use App\Domain\Care\Tickets\Enums\TypeEvenement;
use App\Domain\Care\Tickets\Exceptions\TransitionRefusee;
use App\Domain\Care\Tickets\Models\SupportTicket;

/**
 * Les transitions permises d'une demande, ecrites une seule fois.
 *
 * Un statut ne se pose pas : il se franchit. Chaque franchissement est verifie
 * contre cette table, date les jalons (tri, resolution, fermeture) et laisse
 * une ligne dans le journal. Les etats de la chaine technique (IN_REVIEW,
 * FIX_READY, DEPLOYED, VERIFYING) sont deja declares ici ; ce sont les
 * tranches GitHub et deploiement qui les emprunteront.
 */
class TicketStateMachine
{
    /** Transitions qui exigent un motif ecrit. */
    private const MOTIF_REQUIS = [S::Rejected, S::Duplicate];

    public function __construct(private readonly Journal $journal)
    {
    }

    /** @return array<string, list<S>> */
    public static function transitions(): array
    {
        $traitement = [S::Triaged, S::WaitingSupport, S::WaitingCustomer, S::Confirmed,
            S::LinkedToKnownIssue, S::EscalatedProduct, S::EscalatedEngineering,
            S::InProgress, S::Resolved, S::Rejected, S::Duplicate];

        return [
            S::New->value => [S::TriagePending],
            S::TriagePending->value => $traitement,
            S::Triaged->value => $traitement,
            S::WaitingSupport->value => $traitement,
            S::WaitingCustomer->value => $traitement,
            S::Confirmed->value => $traitement,
            S::LinkedToKnownIssue->value => $traitement,
            S::EscalatedProduct->value => $traitement,
            S::EscalatedEngineering->value => [S::InProgress, S::WaitingCustomer, S::Rejected, S::Duplicate, S::Resolved],
            S::InProgress->value => [S::InReview, S::FixReady, S::WaitingCustomer, S::Resolved, S::Rejected],
            S::InReview->value => [S::InProgress, S::FixReady],
            S::FixReady->value => [S::Deployed, S::InProgress],
            S::Deployed->value => [S::Verifying, S::Resolved, S::InProgress],
            S::Verifying->value => [S::Resolved, S::InProgress],
            S::Resolved->value => [S::Closed, S::Triaged],
            S::Closed->value => [S::Triaged],
            S::Rejected->value => [S::Triaged],
            S::Duplicate->value => [S::Triaged],
        ];
    }

    /** @return list<S> */
    public function suivants(S $depuis): array
    {
        return self::transitions()[$depuis->value] ?? [];
    }

    public function peut(S $depuis, S $vers): bool
    {
        return in_array($vers, $this->suivants($depuis), true);
    }

    public function franchir(SupportTicket $ticket, S $vers, Acteur $acteur, ?string $motif = null): SupportTicket
    {
        $depuis = $ticket->status;

        if (! $this->peut($depuis, $vers)) {
            throw new TransitionRefusee("Transition refusée : {$depuis->value} → {$vers->value}.");
        }

        $motif = $motif !== null ? trim($motif) : null;
        if (in_array($vers, self::MOTIF_REQUIS, true) && ($motif === null || mb_strlen($motif) < 10)) {
            throw new TransitionRefusee("Un motif d'au moins 10 caractères est requis pour passer à « {$vers->libelle()} ».");
        }

        $ticket->forceFill(['status' => $vers] + $this->jalons($ticket, $depuis, $vers))->save();

        $this->journal->consigner($ticket, TypeEvenement::StatutChange, $acteur, $depuis->value, $vers->value, $motif);

        return $ticket;
    }

    /** Dates des jalons. Une reouverture efface la date de resolution et de fermeture. */
    private function jalons(SupportTicket $ticket, S $depuis, S $vers): array
    {
        $maintenant = now();
        $jalons = [];

        if ($depuis === S::TriagePending && $ticket->triaged_at === null) {
            $jalons['triaged_at'] = $maintenant;
        }
        if ($vers === S::Resolved) {
            $jalons['resolved_at'] = $maintenant;
        }
        if (in_array($vers, [S::Closed, S::Rejected, S::Duplicate], true)) {
            $jalons['closed_at'] = $maintenant;
        }
        if ($vers === S::Triaged && ! $depuis->estOuvert()) {
            $jalons['resolved_at'] = null;
            $jalons['closed_at'] = null;
        }

        return $jalons;
    }
}
