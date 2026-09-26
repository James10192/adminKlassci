<?php

namespace App\Domain\AssistantIa;

use App\Models\Tenant;
use App\Services\TenantConnectionManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Rapatrie les avis 👍 / 👎 d'une école. Contrairement à la consommation, un avis
 * CHANGE (la personne passe de 👍 à 👎, un signalement Care s'y rattache) : on
 * relit donc tout ce qui a bougé depuis FENETRE_JOURS et on met à jour, par
 * (école, identifiant source). Les avis sont rares : une fenêtre large ne coûte rien.
 */
class SynchronisationRetours
{
    private const FENETRE_JOURS = 45;

    public function __construct(private TenantConnectionManager $connexions)
    {
    }

    /** @return int avis copiés ou mis à jour ; -1 si l'école n'a pas encore la table */
    public function synchroniser(Tenant $tenant): int
    {
        $connexion = $this->connexions->createConnection($tenant);

        try {
            if (! Schema::connection($connexion)->hasTable('assistant_retours')) {
                return -1;
            }

            $n = 0;
            DB::connection($connexion)->table('assistant_retours as r')
                ->leftJoin('users as u', 'u.id', '=', 'r.user_id')
                ->where('r.updated_at', '>=', now()->subDays(self::FENETRE_JOURS))
                ->orderBy('r.id')
                ->select(['r.*', 'u.name as nom_utilisateur'])
                ->chunk(500, function ($lignes) use ($tenant, &$n) {
                    RetourIa::upsert($lignes->map(fn ($l) => [
                        'tenant_id' => $tenant->id,
                        'source_id' => $l->id,
                        'message_id' => $l->message_id,
                        'user_id' => $l->user_id,
                        'nom_utilisateur' => $l->nom_utilisateur,
                        'avis' => $l->avis,
                        'raison' => $l->raison,
                        'commentaire' => $l->commentaire,
                        'modele' => $l->modele,
                        'palier' => $l->palier,
                        'care_reference' => $l->care_reference,
                        'survenue_at' => $l->created_at,
                        'modifie_at' => $l->updated_at,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ])->all(), ['tenant_id', 'source_id'], ['avis', 'raison', 'commentaire', 'modele', 'palier', 'care_reference', 'nom_utilisateur', 'modifie_at', 'updated_at']);
                    $n += $lignes->count();
                });

            return $n;
        } finally {
            $this->connexions->closeConnection($connexion);
        }
    }
}
