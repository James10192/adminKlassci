<?php

namespace App\Http\Controllers\API;

use App\Filament\Group\Resources\EstablishmentResource;
use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Services\TenantAggregationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Called by tenant apps after state-changing events (paiement validé, inscription created)
 * to force-refresh group portal caches immediately instead of waiting for TTL (2-5min).
 *
 * Auth: tenant.api middleware (same Bearer token as /api/tenants/{code}/limits).
 * Route: POST /api/tenants/{code}/cache/invalidate
 */
class TenantCacheInvalidateController extends Controller
{
    public function __invoke(Request $request, string $code): JsonResponse
    {
        $tenant = Tenant::where('code', $code)->first();
        if (! $tenant) {
            return response()->json(['error' => 'Tenant not found'], 404);
        }

        if (! $tenant->group_id) {
            // Tenant not attached to a group — nothing to invalidate at the group level.
            return response()->json(['message' => 'No group attached, no-op'], 200);
        }

        $group = $tenant->group;
        app(TenantAggregationService::class)->refreshGroupCache($group);
        EstablishmentResource::forgetAlertsCache($group->id);

        Log::info("Group cache invalidated for {$code} (group {$group->code})", [
            'triggered_by' => $request->input('trigger', 'unknown'),
            'ip' => $request->ip(),
        ]);

        return response()->json([
            'message' => 'Group cache invalidated',
            'group_code' => $group->code,
            'tenant_code' => $code,
        ]);
    }
}
