<?php

namespace App\Http\Controllers\API;

use App\Domain\Deploiement\DemanderDeploiement;
use App\Domain\Deploiement\DeploiementDejaDemande;
use App\Http\Controllers\Controller;
use App\Http\Requests\DeployWebhookRequest;
use App\Models\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;

class DeployWebhookController extends Controller
{
    /**
     * Endpoint appelé par GitHub Actions pour déclencher un déploiement.
     *
     * Sécurisé par DEPLOY_WEBHOOK_TOKEN dans .env
     * GitHub Actions envoie : POST /api/deploy
     * Headers : Authorization: Bearer {DEPLOY_WEBHOOK_TOKEN}
     * Body JSON : { tenant_code, branch, skip_backup, skip_migrations }
     */
    public function __invoke(DeployWebhookRequest $request): JsonResponse
    {
        // Jeton et forme de la charge utile : DeployWebhookRequest.
        // Le nom de branche y passe NomDeBrancheGit, et TenantDeploy le revérifie.
        $validated = $request->validated();

        $tenantCode     = $validated['tenant_code'] ?? null;
        $branch         = $validated['branch'] ?? null;
        $skipBackup     = $validated['skip_backup'] ?? false;
        $skipMigrations = $validated['skip_migrations'] ?? false;

        if ($tenantCode) {
            // Une école : même garde que le CLI et le panneau, pour ne jamais
            // enchaîner deux déploiements sur le même site.
            try {
                app(DemanderDeploiement::class)->demander(
                    Tenant::where('code', $tenantCode)->firstOrFail(),
                    branche: $branch,
                    sansSauvegarde: (bool) $skipBackup,
                    sansMigrations: (bool) $skipMigrations,
                );
            } catch (DeploiementDejaDemande $e) {
                return response()->json(['success' => false, 'message' => $e->getMessage()], 409);
            }
        } else {
            // Sans école : tenant:deploy parcourt les écoles actives une à une.
            Artisan::queue('tenant:deploy', array_filter([
                '--branch' => $branch,
                '--skip-backup' => (bool) $skipBackup,
                '--skip-migrations' => (bool) $skipMigrations,
            ]));
        }

        $label = $tenantCode ?? '(tous les tenants actifs)';

        Log::info('DeployWebhook: Déploiement déclenché', [
            'tenant'          => $label,
            'branch'          => $branch ?? '(défaut)',
            'skip_backup'     => $skipBackup,
            'skip_migrations' => $skipMigrations,
            'triggered_by'    => $request->header('X-GitHub-Actor', 'GitHub Actions'),
        ]);

        return response()->json([
            'success' => true,
            'message' => "Déploiement de « {$label} » mis en file d'attente.",
            'queued'  => [
                'tenant'          => $label,
                'branch'          => $branch ?? '(défaut tenant)',
                'skip_backup'     => $skipBackup,
                'skip_migrations' => $skipMigrations,
            ],
        ], 202);
    }
}
