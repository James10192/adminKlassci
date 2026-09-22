<?php

namespace App\Domain\Care\Tickets\Services;

use App\Domain\Care\Tickets\Enums\StatutTicket as S;
use App\Domain\Care\Tickets\Enums\TypeEvenement;
use App\Domain\Care\Tickets\Exceptions\TransitionRefusee;
use App\Domain\Care\Tickets\Models\SupportTicket;
use Illuminate\Support\Facades\DB;

/**
 * Les transitions permises d'une demande, ecrites une seule fois.
 *
 * Un statut ne se pose pas : il se franchit. Chaque franchissement est verifie
 * contre cette table, date les jalons (tri, resolution, fermeture) et laisse
 * une ligne dans le journal.
 *
 * Un statut n'entre dans cette table que le jour ou un ecran sait le servir.
 * WAITING_CUSTOMER montre a l'ecole « Action requise » : il est une cible
 * depuis que l'ecole peut repondre (RepondreParLEcole), et sa reponse le
 * ramene en WAITING_SUPPORT.
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
            S::EscalatedEngineering->value => [S::InProgress, S::Rejected, S::Duplicate, S::Resolved],
            S::InProgress->value => [S::Resolved, S::Rejected],
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

    /**
     * La regle se verifie contre l'etat relu sous verrou, pas contre celui que
     * l'appelant a charge : deux agents sur le meme dossier ne peuvent pas
     * s'ecraser, et le journal consigne l'etat de depart reel.
     */
    public function franchir(SupportTicket $ticket, S $vers, Acteur $acteur, ?string $motif = null): SupportTicket
    {
        $motif = $motif !== null ? trim($motif) : null;
        if (in_array($vers, self::MOTIF_REQUIS, true) && ! self::motifSuffisant($motif)) {
            throw new TransitionRefusee('Un motif d\'au moins '.self::motifMin()." caractères est requis pour passer à « {$vers->libelle()} ».");
        }

        return DB::transaction(function () use ($ticket, $vers, $acteur, $motif) {
            $courant = SupportTicket::whereKey($ticket->getKey())->lockForUpdate()->firstOrFail();
            $depuis = $courant->status;

            if (! $this->peut($depuis, $vers)) {
                throw new TransitionRefusee("Transition refusée : {$depuis->value} → {$vers->value}.");
            }

            $courant->forceFill(['status' => $vers] + $this->jalons($courant, $depuis, $vers))->save();
            $this->journal->consigner($courant, TypeEvenement::StatutChange, $acteur, $depuis->value, $vers->value, $motif);

            $ticket->setRawAttributes($courant->getAttributes(), true);

            return $ticket;
        });
    }

    public static function motifMin(): int
    {
        return (int) config('care.limites.motif_min');
    }

    public static function motifSuffisant(?string $motif): bool
    {
        return $motif !== null && mb_strlen(trim($motif)) >= self::motifMin();
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
