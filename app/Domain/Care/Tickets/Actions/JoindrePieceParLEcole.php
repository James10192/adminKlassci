<?php

namespace App\Domain\Care\Tickets\Actions;

use App\Domain\Care\Tickets\Enums\TypeEvenement;
use App\Domain\Care\Tickets\Enums\VisibiliteMessage;
use App\Domain\Care\Tickets\Exceptions\CleIdempotenceReutilisee;
use App\Domain\Care\Tickets\Exceptions\DemandeClose;
use App\Domain\Care\Tickets\Exceptions\PieceJointeRefusee;
use App\Domain\Care\Tickets\Models\SupportTicket;
use App\Domain\Care\Tickets\Models\SupportTicketAttachment;
use App\Domain\Care\Tickets\Services\Acteur;
use App\Domain\Care\Tickets\Services\AssainissementPieceJointe;
use App\Domain\Care\Tickets\Services\Journal;
use App\Domain\Care\Tickets\Services\TicketStateMachine;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * L'ecole joint un fichier a sa demande.
 *
 * Ce qui est stocke est ce que l'assainissement a produit, jamais ce qui a ete
 * recu. Le fichier est ecrit sur un disque prive avant la ligne qui le
 * reference, et retire si la transaction echoue : aucune ligne ne pointe vers un
 * fichier absent.
 *
 * Idempotente par la cle de l'envoi : un renvoi apres une reponse perdue
 * retrouve sa piece. Une meme cle sur d'autres octets est refusee.
 *
 * Un echec apres l'ecriture (exception) retire le fichier, et un retrait rate
 * se journalise. Un arret brutal entre l'ecriture et la validation, lui, peut
 * laisser un fichier sans ligne : rien ne les balaie encore.
 */
class JoindrePieceParLEcole
{
    public function __construct(
        private readonly AssainissementPieceJointe $assainissement,
        private readonly Journal $journal,
        private readonly TicketStateMachine $etats,
    ) {
    }

    /** @return array{0: SupportTicketAttachment, 1: bool} la piece, et true si c'etait un renvoi */
    public function executer(SupportTicket $ticket, int $rapporteur, ?string $nom, string $contenu, ?string $nomEnvoye, string $cle): array
    {
        // Un renvoi se reconnait aux octets recus, avant tout decodage : il ne paie
        // pas l'assainissement, et une mise a jour de GD entre l'envoi et le renvoi
        // ne le fait pas passer pour une cle reutilisee.
        $recu = hash('sha256', $contenu);
        $deja = $this->renvoi($ticket, $cle, $recu);
        if ($deja !== null) {
            return [$deja, true];
        }

        // Hors transaction : decoder une image prend du temps, le verrou n'a pas a l'attendre.
        $fichier = $this->assainissement->assainir($contenu, $nomEnvoye);
        $disque = (string) config('care.pieces_jointes.disque');
        $chemin = sprintf('care/%d/%d/%s.%s', $ticket->tenant_id, $ticket->getKey(), Str::uuid(), $fichier->extension);
        $ecrit = false;

        try {
            return DB::transaction(function () use ($ticket, $rapporteur, $nom, $fichier, $disque, $chemin, $cle, $recu, &$ecrit) {
                $courant = SupportTicket::whereKey($ticket->getKey())->lockForUpdate()->firstOrFail();

                // Deux envois simultanes de la meme cle : le second trouve ici la piece du premier.
                $deja = $this->renvoi($courant, $cle, $recu);
                if ($deja !== null) {
                    return [$deja, true];
                }

                if (TicketStateMachine::fermeeALEcole($courant->status)) {
                    throw new DemandeClose('Cette demande est close. Signalez un nouveau problème si besoin.');
                }

                $max = (int) config('care.pieces_jointes.par_demande_max');
                if ($courant->piecesJointes()->count() >= $max) {
                    throw new PieceJointeRefusee("Cette demande a déjà {$max} pièces jointes.");
                }

                if (! Storage::disk($disque)->put($chemin, $fichier->contenu)) {
                    throw new \RuntimeException("Écriture impossible sur le disque « {$disque} ».");
                }
                $ecrit = true;

                $acteur = Acteur::client($rapporteur, $nom);
                $piece = $courant->piecesJointes()->create([
                    'author_type' => $acteur->type,
                    'author_ref' => $acteur->reference,
                    'author_name' => $nom,
                    'visibility' => VisibiliteMessage::PublicClient,
                    'original_name' => $fichier->nom,
                    'mime' => $fichier->mime,
                    'size_bytes' => strlen($fichier->contenu),
                    'width' => $fichier->largeur,
                    'height' => $fichier->hauteur,
                    'disk' => $disque,
                    'path' => $chemin,
                    'sha256' => $fichier->empreinte(),
                    'received_sha256' => $recu,
                    'client_key' => $cle,
                ]);
                $this->journal->consigner($courant, TypeEvenement::PieceJointeClient, $acteur, details: ['piece_id' => $piece->id]);
                $this->etats->rendreLaMainAuSupport($courant, $acteur);

                $ticket->setRawAttributes($courant->getAttributes(), true);

                return [$piece, false];
            });
        } catch (\Throwable $e) {
            if ($ecrit && ! Storage::disk($disque)->delete($chemin)) {
                Log::error('KLASSCI Care : fichier orphelin après un échec de pièce jointe', ['disque' => $disque, 'chemin' => $chemin]);
            }
            throw $e;
        }
    }

    /** La piece deja enregistree sous cette cle, ou null. Une autre empreinte est un refus. */
    private function renvoi(SupportTicket $ticket, string $cle, string $recu): ?SupportTicketAttachment
    {
        $existante = $ticket->piecesJointes()->where('client_key', $cle)->first();
        if ($existante === null) {
            return null;
        }
        if ($existante->received_sha256 !== $recu) {
            throw new CleIdempotenceReutilisee('Cette clé a déjà servi pour un autre fichier.');
        }

        return $existante;
    }
}
