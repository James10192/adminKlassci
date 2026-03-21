<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use Illuminate\Http\JsonResponse;

/**
 * Registre des tenants pour le LMS
 *
 * Permet au LMS de découvrir dynamiquement tous les tenants actifs
 * et leurs URLs API pour le login unifié multi-établissements.
 *
 * Protégé par le middleware tenant.api (Bearer token).
 */
class LMSRegistryController extends Controller
{
    /**
     * Liste tous les tenants actifs avec leurs URLs API LMS
     *
     * Endpoint: GET /api/lms/tenants
     * Auth: Bearer {tenant_api_token}
     *
     * @return JsonResponse
     */
    public function tenants(): JsonResponse
    {
        $tenants = Tenant::active()
            ->orderBy('name')
            ->get(['id', 'code', 'name', 'subdomain', 'plan', 'status']);

        $data = $tenants->map(function (Tenant $tenant) {
            return [
                'code' => $tenant->code,
                'name' => $tenant->name,
                'subdomain' => $tenant->subdomain,
                'url' => $tenant->full_url,
                'api_base_url' => $tenant->full_url . '/api/lms',
                'login_url' => $tenant->full_url . '/api/lms/auth/login',
                'check_user_url' => $tenant->full_url . '/api/lms/auth/check-user',
                'tenant_info_url' => $tenant->full_url . '/api/lms/tenant-info',
                'plan' => $tenant->plan,
            ];
        });

        return response()->json([
            'success' => true,
            'data' => [
                'tenants' => $data,
                'count' => $data->count(),
            ],
            'meta' => [
                'timestamp' => now()->toISOString(),
                'api_version' => '1.0',
                'usage' => [
                    'description' => 'Liste des établissements KLASSCI actifs pour intégration LMS',
                    'login_flow' => 'Utiliser check_user_url sur chaque tenant pour trouver où un utilisateur existe, puis login_url pour authentifier.',
                ],
            ],
        ]);
    }
}
