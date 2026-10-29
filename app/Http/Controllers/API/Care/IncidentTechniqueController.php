<?php

namespace App\Http\Controllers\API\Care;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Models\TenantErrorEvent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Evenements de production sans donnees personnelles.
 * L'instance vient EXCLUSIVEMENT du jeton Care verifie par middleware.
 */
class IncidentTechniqueController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'request_id' => ['required', 'string', 'min:16', 'max:64', 'regex:/^[A-Za-z0-9_-]+$/D'],
            'route_name' => ['nullable', 'string', 'max:160', 'regex:/^[A-Za-z0-9_.:-]+$/D'],
            'exception_class' => ['required', 'string', 'max:160', 'regex:/^[A-Za-z0-9_\\\\]+$/D'],
            'category' => ['required', Rule::in(['database', 'application', 'infrastructure'])],
            'method' => ['required', Rule::in(['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'HEAD'])],
            'http_status' => ['required', 'integer', 'between:500,599'],
            'sqlstate' => ['nullable', 'string', 'regex:/^[A-Z0-9]{5}$/D'],
            'db_errno' => ['nullable', 'integer', 'between:0,65535'],
        ]);

        /** @var Tenant $tenant */
        $tenant = $request->attributes->get('care_tenant');
        $fingerprint = hash('sha256', implode('|', [
            $payload['route_name'] ?? '',
            $payload['exception_class'],
            $payload['category'],
            $payload['sqlstate'] ?? '',
            (string) ($payload['db_errno'] ?? ''),
        ]));

        // La cle unique assure l'idempotence, y compris en cas de renvoi concurrent.
        // Aucun champ inconnu du corps n'est copie en base.
        $created = DB::table('tenant_error_events')->insertOrIgnore([
            'tenant_id' => $tenant->id,
            'request_id' => $payload['request_id'],
            'fingerprint' => $fingerprint,
            'route_name' => $payload['route_name'] ?? null,
            'exception_class' => $payload['exception_class'],
            'category' => $payload['category'],
            'method' => $payload['method'],
            'http_status' => $payload['http_status'],
            'sqlstate' => $payload['sqlstate'] ?? null,
            'db_errno' => $payload['db_errno'] ?? null,
            'occurred_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $event = TenantErrorEvent::query()->where('tenant_id', $tenant->id)
            ->where('request_id', $payload['request_id'])->firstOrFail();

        return response()->json(['id' => $event->id, 'replayed' => $created === 0], $created ? 201 : 200);
    }
}
