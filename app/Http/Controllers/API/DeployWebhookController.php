<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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
    public function __invoke(Request $request): JsonResponse
    {
        // Vérification du token secret
        $expectedToken = config('app.deploy_webhook_token');

        if (empty($expectedToken)) {
            Log::error('DeployWebhook: DEPLOY_WEBHOOK_TOKEN non configuré dans .env');
            return response()->json(['error' => 'Webhook non configuré côté serveur.'], 500);
        }

        $token = $request->bearerToken();

        if (!$token || !hash_equals($expectedToken, $token)) {
            Log::warning('DeployWebhook: Tentative non autorisée', [
                'ip' => $request->ip(),
                'user_agent' => $request->userAgent(),
            ]);
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        // Validation du payload
        $validated = $request->validate([
            'tenant_code'      => 'nullable|string|max:100|regex:/^[a-z0-9\-]+$/',
            'branch'           => 'nullable|string|max:100',
            'skip_backup'      => 'nullable|boolean',
            'skip_migrations'  => 'nullable|boolean',
        ]);

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
