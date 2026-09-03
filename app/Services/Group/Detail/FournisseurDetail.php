<?php

namespace App\Services\Group\Detail;

use App\Models\Group;
use App\Models\Tenant;
use App\Services\TenantConnectionManager;
use App\Support\EtatMesure;
use App\Support\Filtres\FiltresRapport;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * La traversée des bases d'établissement pour un état de DÉTAIL.
 *
 * Les états agrégés du portail passent par `TenantAggregator`, qui répartit le
 * travail sur un pool de processus. Le détail ne le fait PAS, et c'est
 * délibéré : ces requêtes ramènent des milliers de lignes chacune, que la
 * concurrence de Laravel devrait sérialiser pour les faire franchir la
 * frontière des processus. Le coût de ce transport dépasse le gain du
 * parallélisme, et il le dépasse d'autant plus que le résultat est gros. Une
 * traversée séquentielle est ici plus rapide et infiniment plus simple à lire.
 *
 * Le vrai apport de cette classe est ailleurs, et il est de correction, pas de
 * performance : quand la base d'une école ne répond pas, ses lignes sont
 * ABSENTES du document. Une absence de lignes ne se voit pas — contrairement à
 * une cellule vide, elle ne laisse aucune trace. Le directeur lirait « peu de
 * paiements ce mois-ci » là où il faut lire « une école n'a pas répondu ».
 *
 * C'est le même principe que le tiret des états agrégés, appliqué aux lignes
 * plutôt qu'aux cellules — et il est plus dangereux ici, précisément parce
 * qu'il est invisible. Chaque traversée compte donc qui a répondu, qui n'a pas
 * répondu et pourquoi, et le rapport porte ce décompte dans son bandeau.
 */
abstract class FournisseurDetail
{
    public function __construct(
        protected readonly TenantConnectionManager $connectionManager,
    ) {
    }

    /**
     * Interroge chaque école du périmètre et rassemble les lignes.
     *
     * @param  callable(string $connexion, Tenant $etablissement, object $annee): array<int, array<string, mixed>>  $requete
     * @return array{lignes: array<int, array<string, mixed>>, total: int, repondants: int, manquants: array<string, array{nom: string, motif: string}>}
     */
    protected function parcourir(Group $groupe, FiltresRapport $filtres, callable $requete): array
    {
        $lignes = [];
        $manquants = [];
        $repondants = 0;
        $total = 0;

        foreach ($groupe->activeTenants as $etablissement) {
            if (! $filtres->retient($etablissement->code)) {
                continue;
            }

            $total++;

            [$lignesEtablissement, $motif] = $this->interroger($etablissement, $requete);

            if ($motif !== null) {
                $manquants[$etablissement->code] = ['nom' => $etablissement->name, 'motif' => $motif];

                continue;
            }

            $repondants++;

            foreach ($lignesEtablissement as $ligne) {
                $lignes[] = ['etablissement' => $etablissement->name] + $ligne;
            }
        }

        // Le tri est fait ici, une fois toutes les écoles rassemblées : trier
        // école par école donnerait un document classé par établissement, ce
        // qui n'est pas la lecture attendue d'un journal de paiements.
        $lignes = $this->ordonner($lignes);

        return compact('lignes', 'total', 'repondants', 'manquants');
    }

    /**
     * Une école : connexion, année courante, requête, fermeture.
     *
     * @param  callable(string, Tenant, object): array<int, array<string, mixed>>  $requete
     * @return array{0: array<int, array<string, mixed>>, 1: ?string}  [lignes, motif d'absence]
     */
    private function interroger(Tenant $etablissement, callable $requete): array
    {
        $connexion = null;

        try {
            $connexion = $this->connectionManager->createConnection($etablissement);

            $annee = DB::connection($connexion)
                ->table('esbtp_annee_universitaires')
                ->where('is_current', 1)
                ->first();

            if (! $annee) {
                // La base a répondu. Ce n'est pas une panne : c'est une école
                // qui n'a pas ouvert son année. Les deux se disent autrement.
                return [[], EtatMesure::MOTIF_SANS_ANNEE];
            }

            return [$requete($connexion, $etablissement, $annee), null];
        } catch (\Throwable $e) {
            Log::error(sprintf(
                '[rapports-detail] %s a échoué pour %s : %s',
                static::class,
                $etablissement->code,
                $e->getMessage(),
            ));

            return [[], EtatMesure::MOTIF_INJOIGNABLE];
        } finally {
            if ($connexion !== null) {
                $this->connectionManager->closeConnection($connexion);
            }
        }
    }

    /**
     * L'ordre du document, une fois toutes les écoles rassemblées.
     *
     * @param  array<int, array<string, mixed>>  $lignes
     * @return array<int, array<string, mixed>>
     */
    abstract protected function ordonner(array $lignes): array;
}
