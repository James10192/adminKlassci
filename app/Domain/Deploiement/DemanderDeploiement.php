<?php

namespace App\Domain\Deploiement;

use App\Models\Tenant;
use App\Models\TenantDeployment;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;

/**
 * Seul chemin pour demander le déploiement d'UNE école : le CLI, les boutons
 * du panneau et le webhook passent tous ici. Le webhook sans école (tout le
 * parc) n'y passe pas : il n'y a pas d'école à verrouiller. Le travailleur
 * unique exécute les lots l'un après l'autre, donc rien ne se chevauche, mais
 * une école peut alors être déployée deux fois de suite.
 *
 * Un déploiement met le site en maintenance et tire le code : deux à la suite
 * sur la même école se marchent dessus. La vérification et la mise en file se
 * font donc sous un verrou, sinon deux demandes simultanées passent toutes
 * les deux le contrôle avant que l'une ait écrit quoi que ce soit.
 *
 * Il dure plusieurs minutes, plus qu'une requête web (30 s sur LWS) : il part
 * toujours en file d'attente, jamais dans la requête.
 */
final class DemanderDeploiement
{
    /** Au-delà, un déploiement « en cours » est tenu pour abandonné. */
    public const EN_COURS_MAX_MINUTES = 45;

    /**
     * Temps laissé à la file pour démarrer une demande avant de l'oublier.
     * La file n'a qu'un travailleur, qui peut rester jusqu'à 55 minutes sur un
     * même lot (--max-time=3300) : une demande patiente derrière lui aussi
     * longtemps, et ne doit pas être oubliée entre-temps.
     */
    private const ATTENTE_MAX_MINUTES = 60;

    /**
     * @throws DeploiementDejaDemande
     */
    public function demander(
        Tenant $ecole,
        ?string $branche = null,
        bool $sansSauvegarde = false,
        bool $sansMigrations = false,
        ?int $parMembre = null,
    ): void {
        $verrou = Cache::lock("deploiement:verrou:{$ecole->code}", 10);

        if (! $verrou->get()) {
            throw new DeploiementDejaDemande($ecole->code);
        }

        try {
            if ($this->dejaDemande($ecole)) {
                throw new DeploiementDejaDemande($ecole->code);
            }

            Artisan::queue('tenant:deploy', array_filter([
                'tenant' => $ecole->code,
                '--branch' => $branche,
                '--skip-backup' => $sansSauvegarde,
                '--skip-migrations' => $sansMigrations,
                '--par' => $parMembre,
            ]));

            Cache::put($this->cleAttente($ecole), now()->subSecond(), now()->addMinutes(self::ATTENTE_MAX_MINUTES));
        } finally {
            $verrou->release();
        }
    }

    public function dejaDemande(Tenant $ecole): bool
    {
        $enCours = TenantDeployment::where('tenant_id', $ecole->id)
            ->where('status', 'in_progress')
            ->where('started_at', '>=', now()->subMinutes(self::EN_COURS_MAX_MINUTES))
            ->exists();

        if ($enCours) {
            return true;
        }

        // Entre la mise en file et le démarrage (jusqu'à une minute), aucune
        // ligne « en cours » n'existe encore : la demande reste en attente tant
        // qu'aucun déploiement de l'école n'a démarré depuis.
        $demandeLe = Cache::get($this->cleAttente($ecole));

        return $demandeLe !== null && ! TenantDeployment::where('tenant_id', $ecole->id)
            ->where('created_at', '>=', $demandeLe)
            ->exists();
    }

    private function cleAttente(Tenant $ecole): string
    {
        return "deploiement:en-attente:{$ecole->code}";
    }
}
