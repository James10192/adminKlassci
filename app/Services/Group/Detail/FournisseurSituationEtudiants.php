<?php

namespace App\Services\Group\Detail;

use App\Models\Group;
use App\Services\Group\TenantBillingContext;
use App\Services\TenantConnectionManager;
use App\Support\Filtres\FiltresRapport;
use Illuminate\Support\Facades\DB;

/**
 * Qui doit combien, école par école, étudiant par étudiant.
 *
 * C'est le document du recouvrement, là où le journal des paiements est celui
 * de la caisse : l'un dit ce qui est rentré, l'autre ce qui manque.
 *
 * L'attendu n'est PAS recalculé ici. Il passe par `TenantBillingContext`, qui
 * porte déjà la règle métier — catégories obligatoires, souscriptions
 * individuelles, barème par filière et niveau, et le montant qui dépend du
 * statut d'affectation. Réécrire ce calcul pour un rapport donnerait deux
 * vérités sur le même chiffre, et elles divergeraient au premier changement de
 * barème. La consolidation du groupe et la situation d'un étudiant doivent
 * s'additionner ; elles ne le feraient plus.
 */
class FournisseurSituationEtudiants extends FournisseurDetail
{
    public function __construct(
        TenantConnectionManager $connectionManager,
        private readonly TenantBillingContext $billing,
    ) {
        parent::__construct($connectionManager);
    }

    /**
     * @return array{lignes: array<int, array<string, mixed>>, total: int, repondants: int, manquants: array<string, array{nom: string, motif: string}>}
     */
    public function pourGroupe(Group $groupe, FiltresRapport $filtres): array
    {
        return $this->parcourir($groupe, $filtres, function (string $conn, $etablissement, object $annee) use ($filtres): array {
            $contexte = $this->billing->load($conn, (int) $etablissement->id, (int) $annee->id);

            if ($contexte['inscriptions']->isEmpty()) {
                return [];
            }

            $inscriptions = $contexte['inscriptions'];

            if ($filtres->statutInscription !== null) {
                $inscriptions = $inscriptions->where('status', $filtres->statutInscription);
            }

            if ($inscriptions->isEmpty()) {
                return [];
            }

            $identites = $this->identites($conn, $inscriptions->pluck('etudiant_id')->all());
            $classes = $this->classesParInscription($conn, $inscriptions->pluck('id')->all());
            $encaisses = $this->encaissesParInscription($conn, $inscriptions->pluck('id')->all(), (int) $annee->id);

            $lignes = [];

            foreach ($inscriptions as $inscription) {
                $souscriptions = $contexte['subscriptions']->get($inscription->id, collect());

                $attendu = 0.0;
                foreach ($contexte['categories'] as $categorie) {
                    $attendu += $this->billing->resolveCategoryAmount(
                        $inscription,
                        $categorie,
                        $souscriptions,
                        $contexte['configurations'],
                    );
                }

                $encaisse = (float) ($encaisses[$inscription->id]['montant'] ?? 0.0);
                $identite = $identites[$inscription->etudiant_id] ?? null;

                $lignes[] = [
                    'matricule' => $identite->matricule ?? null,
                    'etudiant' => $identite
                        ? trim(($identite->nom ?? '') . ' ' . ($identite->prenoms ?? ''))
                        : null,
                    'classe' => $classes[$inscription->id] ?? null,
                    'attendu' => $attendu,
                    'encaisse' => $encaisse,
                    // Jamais négatif : un étudiant qui a trop versé n'a pas une
                    // dette de signe inverse, il a un avoir — que ce document
                    // ne traite pas et n'a pas à laisser deviner.
                    'reste' => max(0.0, $attendu - $encaisse),
                    // Sans attendu, il n'y a pas de taux. Un « 0 % » sous une
                    // ligne dont l'école n'a pas configuré ses frais se lit
                    // comme un défaut de paiement de l'étudiant.
                    'taux' => $attendu > 0 ? min(100.0, round(($encaisse / $attendu) * 100, 1)) : null,
                    // L'ancienneté se compte depuis le DERNIER versement, ou à
                    // défaut depuis l'inscription : c'est la question que pose
                    // un comptable devant un impayé — depuis quand ?
                    'dernier_paiement' => $encaisses[$inscription->id]['dernier'] ?? null,
                ];
            }

            return $lignes;
        });
    }

    /**
     * Identités des étudiants concernés, en une requête.
     *
     * @param  array<int, int>  $etudiantIds
     * @return array<int, object>
     */
    private function identites(string $conn, array $etudiantIds): array
    {
        if ($etudiantIds === []) {
            return [];
        }

        return DB::connection($conn)
            ->table('esbtp_etudiants')
            ->whereIn('id', $etudiantIds)
            ->get(['id', 'matricule', 'nom', 'prenoms'])
            ->keyBy('id')
            ->all();
    }

    /**
     * Classe de chaque inscription, en une requête.
     *
     * @param  array<int, int>  $inscriptionIds
     * @return array<int, ?string>
     */
    private function classesParInscription(string $conn, array $inscriptionIds): array
    {
        if ($inscriptionIds === []) {
            return [];
        }

        return DB::connection($conn)
            ->table('esbtp_inscriptions as i')
            ->leftJoin('esbtp_classes as c', 'c.id', '=', 'i.classe_id')
            ->whereIn('i.id', $inscriptionIds)
            ->pluck('c.name', 'i.id')
            ->all();
    }

    /**
     * Montant encaissé et date du dernier versement, par inscription.
     *
     * Seuls les paiements VALIDÉS entrent dans l'encaissé : un versement en
     * attente n'est pas de la trésorerie, et le compter effacerait de la liste
     * de relance exactement les dossiers qu'il faut relancer.
     *
     * @param  array<int, int>  $inscriptionIds
     * @return array<int, array{montant: float, dernier: ?string}>
     */
    private function encaissesParInscription(string $conn, array $inscriptionIds, int $anneeId): array
    {
        if ($inscriptionIds === []) {
            return [];
        }

        return DB::connection($conn)
            ->table('esbtp_paiements')
            ->where('annee_universitaire_id', $anneeId)
            ->where('status', 'validé')
            ->whereNull('deleted_at')
            ->whereIn('inscription_id', $inscriptionIds)
            ->selectRaw('inscription_id, SUM(montant) as montant, MAX(date_paiement) as dernier')
            ->groupBy('inscription_id')
            ->get()
            ->mapWithKeys(fn ($l): array => [
                (int) $l->inscription_id => ['montant' => (float) $l->montant, 'dernier' => $l->dernier],
            ])
            ->all();
    }

    /**
     * Les plus gros restes d'abord.
     *
     * Ce document sert à décider qui relancer. Le classer par nom obligerait à
     * lire huit mille lignes pour trouver les vingt qui comptent.
     *
     * @param  array<int, array<string, mixed>>  $lignes
     * @return array<int, array<string, mixed>>
     */
    protected function ordonner(array $lignes): array
    {
        usort($lignes, static function (array $a, array $b): int {
            // Reste DÉCROISSANT d'abord ; à reste égal, école puis étudiant par
            // ordre alphabétique. Écrit en deux temps plutôt qu'en une
            // comparaison croisée de tableaux : celle-ci fonctionne, mais elle
            // se relit de travers et le premier qui la modifiera inversera un
            // tri sans s'en apercevoir.
            $parReste = ($b['reste'] ?? 0.0) <=> ($a['reste'] ?? 0.0);

            if ($parReste !== 0) {
                return $parReste;
            }

            return [(string) $a['etablissement'], (string) ($a['etudiant'] ?? '')]
                <=> [(string) $b['etablissement'], (string) ($b['etudiant'] ?? '')];
        });

        return $lignes;
    }
}
