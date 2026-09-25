<?php

namespace App\Domain\AssistantIa;

use App\Models\Tenant;
use App\Services\TenantConnectionManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Rapatrie les lignes de consommation d'IA d'une école vers le master.
 *
 * Lecture seule côté école : on lit `assistant_consommations` au-delà de la
 * dernière ligne déjà copiée (identifiant de l'école, `source_id`), par lots.
 * Une ligne copiée ne l'est jamais deux fois (index unique tenant + source_id),
 * donc relancer la commande ne double rien.
 *
 * Recouvrement : on relit les RECOUVREMENT dernières lignes déjà copiées. Deux
 * échanges qui se terminent au même instant peuvent rendre visible la ligne N+1
 * avant la ligne N ; sans relecture, N serait perdue pour toujours.
 *
 * `survenue_at` recopie l'horodatage de l'école, dans SON fuseau (APP_TIMEZONE,
 * UTC+1 au Bénin) : au plus une heure d'écart avec les bornes de mois du master.
 */
class SynchronisationConsommation
{
    private const LOT = 2000;

    private const RECOUVREMENT = 200;

    public function __construct(private TenantConnectionManager $connexions)
    {
    }

    /** @return int lignes copiées ; -1 si l'école n'a pas encore la table (pas déployée) */
    public function synchroniser(Tenant $tenant): int
    {
        $connexion = $this->connexions->createConnection($tenant);

        try {
            if (! Schema::connection($connexion)->hasTable('assistant_consommations')) {
                return -1;
            }

            $avant = ConsommationIa::where('tenant_id', $tenant->id)->count();
            $curseur = max(0, (int) ConsommationIa::where('tenant_id', $tenant->id)->max('source_id') - self::RECOUVREMENT);
            do {
                $lignes = DB::connection($connexion)->table('assistant_consommations as c')
                    ->leftJoin('users as u', 'u.id', '=', 'c.user_id')
                    ->where('c.id', '>', $curseur)
                    ->orderBy('c.id')
                    ->limit(self::LOT)
                    ->get(['c.*', 'u.name as nom_utilisateur']);

                foreach ($lignes->chunk(500) as $paquet) {
                    ConsommationIa::insertOrIgnore($paquet->map(fn ($l) => [
                        'tenant_id' => $tenant->id,
                        'source_id' => $l->id,
                        'survenue_at' => $l->created_at,
                        'user_id' => $l->user_id,
                        'nom_utilisateur' => $l->nom_utilisateur,
                        'fonction' => $l->fonction,
                        'modele' => $l->modele,
                        'fournisseur' => $l->fournisseur,
                        'identifiant_modele' => $l->identifiant_modele,
                        'palier' => $l->palier,
                        'appels' => (int) $l->appels,
                        'tokens_entree' => (int) $l->tokens_entree,
                        'tokens_sortie' => (int) $l->tokens_sortie,
                        'tokens_cache' => (int) $l->tokens_cache,
                        'cout_usd' => (float) $l->cout_usd,
                        'cout_fcfa' => (float) $l->cout_fcfa,
                        'cout_exact' => (bool) $l->cout_exact,
                        'statut' => $l->statut,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ])->all());
                }
                // Le curseur avance sur ce qui a été LU, pas sur ce qui a été inséré :
                // un lot entièrement ignoré ne fait pas tourner la boucle sans fin.
                $curseur = $lignes->isEmpty() ? $curseur : (int) $lignes->last()->id;
            } while ($lignes->count() === self::LOT);

            $copiees = ConsommationIa::where('tenant_id', $tenant->id)->count() - $avant;

            $tenant->forceFill(['ai_usage_synced_at' => now()])->save();

            return $copiees;
        } finally {
            $this->connexions->closeConnection($connexion);
        }
    }
}
