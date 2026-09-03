<?php

namespace App\Services\Group\Detail;

use App\Models\Group;
use App\Support\Filtres\FiltresRapport;
use Illuminate\Support\Facades\DB;

/**
 * Le journal des encaissements du groupe, une ligne par paiement.
 *
 * `GroupFinancialsProvider` lisait déjà cette table — il en tirait des sommes
 * par mois et par mode, puis jetait le détail. Ce fournisseur garde ce qu'on
 * lisait déjà : il n'ouvre aucun accès nouveau sur les bases des écoles.
 *
 * C'est le document de la caisse et du contrôle : qui a payé, quand, combien,
 * par quel moyen, et sous quelle référence.
 */
class FournisseurDetailPaiements extends FournisseurDetail
{
    /**
     * @return array{lignes: array<int, array<string, mixed>>, total: int, repondants: int, manquants: array<string, array{nom: string, motif: string}>}
     */
    public function pourGroupe(Group $groupe, FiltresRapport $filtres): array
    {
        return $this->parcourir($groupe, $filtres, function (string $conn, $etablissement, object $annee) use ($filtres): array {
            $requete = DB::connection($conn)
                ->table('esbtp_paiements as p')
                ->join('esbtp_etudiants as e', 'e.id', '=', 'p.etudiant_id')
                ->leftJoin('esbtp_inscriptions as i', 'i.id', '=', 'p.inscription_id')
                ->leftJoin('esbtp_classes as c', 'c.id', '=', 'i.classe_id')
                ->where('p.annee_universitaire_id', $annee->id)
                // Un paiement supprimé n'a pas à figurer dans un journal
                // d'encaissements : `esbtp_paiements` porte un `softDeletes`,
                // et l'oublier ferait remonter des lignes annulées comme des
                // recettes.
                ->whereNull('p.deleted_at')
                ->whereBetween('p.date_paiement', [
                    $filtres->periode()->startDate()->toDateString(),
                    $filtres->periode()->endDate()->toDateString(),
                ]);

            if ($filtres->statutPaiement !== null) {
                $requete->where('p.status', $filtres->statutPaiement);
            }

            if ($filtres->modePaiement !== null) {
                $requete->where('p.mode_paiement', $filtres->modePaiement);
            }

            return $requete
                ->orderBy('p.date_paiement')
                ->orderBy('p.id')
                ->get([
                    'p.date_paiement', 'p.montant', 'p.type_paiement', 'p.mode_paiement',
                    'p.status', 'p.reference_paiement', 'p.numero_recu', 'p.tranche',
                    'e.matricule', 'e.nom', 'e.prenoms', 'c.name as classe',
                ])
                ->map(fn ($p): array => [
                    'date' => $p->date_paiement,
                    'matricule' => $p->matricule,
                    // Le nom complet est composé ici, pas dans la vue : le
                    // tableur reçoit les mêmes cellules que le PDF, et un
                    // directeur qui trie sa colonne « Étudiant » trie sur ce
                    // qu'il voit.
                    'etudiant' => trim(($p->nom ?? '') . ' ' . ($p->prenoms ?? '')),
                    'classe' => $p->classe,
                    'type' => $p->type_paiement,
                    'mode' => $p->mode_paiement,
                    'montant' => (float) $p->montant,
                    'statut' => $p->status,
                    // Le numéro de reçu prend le relais quand la référence est
                    // vide : dans une caisse, c'est lui qui permet de retrouver
                    // la pièce, et une colonne « Référence » vide n'aide à rien.
                    'reference' => $p->reference_paiement ?: $p->numero_recu,
                ])
                ->all();
        });
    }

    /**
     * Par date, puis par école, puis par étudiant.
     *
     * L'ordre d'un journal de caisse est chronologique : c'est ainsi qu'on le
     * rapproche d'un relevé bancaire. Classer d'abord par école obligerait à
     * parcourir quatre blocs pour reconstituer une journée.
     *
     * @param  array<int, array<string, mixed>>  $lignes
     * @return array<int, array<string, mixed>>
     */
    protected function ordonner(array $lignes): array
    {
        usort($lignes, static function (array $a, array $b): int {
            return [$a['date'], $a['etablissement'], $a['etudiant']]
                <=> [$b['date'], $b['etablissement'], $b['etudiant']];
        });

        return $lignes;
    }
}
