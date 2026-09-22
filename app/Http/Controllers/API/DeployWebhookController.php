<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Requests\DeployWebhookRequest;
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

        // Construction des arguments artisan
        $args = [];

        if ($tenantCode) {
            $args['tenant'] = $tenantCode;
        }

        if ($branch) {
            $args['--branch'] = $branch;
        }

        if ($skipBackup) {
            $args['--skip-backup'] = true;
        }

        if ($skipMigrations) {
            $args['--skip-migrations'] = true;
        }

        // Déclenchement asynchrone via queue
        Artisan::queue('tenant:deploy', $args);

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
