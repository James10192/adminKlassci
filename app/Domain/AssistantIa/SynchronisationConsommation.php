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
 */
class SynchronisationConsommation
{
    private const LOT = 2000;

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

            $copiees = 0;
            do {
                $depuis = (int) ConsommationIa::where('tenant_id', $tenant->id)->max('source_id');
                $lignes = DB::connection($connexion)->table('assistant_consommations as c')
                    ->leftJoin('users as u', 'u.id', '=', 'c.user_id')
                    ->where('c.id', '>', $depuis)
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
                $copiees += $lignes->count();
            } while ($lignes->count() === self::LOT);

            $tenant->forceFill(['ai_usage_synced_at' => now()])->save();

            return $copiees;
        } finally {
            $this->connexions->closeConnection($connexion);
        }
    }
}
