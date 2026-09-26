<?php

namespace App\Http\Controllers\API\Cli;

use App\Http\Controllers\API\Cli\Concerns\ResoutEcole;
use App\Http\Controllers\Controller;
use App\Models\TenantActivityLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** klassci admin:journal — qui a fait quoi, sur quelle école. */
class JournalController extends Controller
{
    use ResoutEcole;

    public function index(Request $request): JsonResponse
    {
        $entrees = TenantActivityLog::with(['tenant:id,code', 'performedBy:id,name'])
            ->when($request->filled('ecole'), fn ($q) => $q->where('tenant_id', $this->ecole($request->string('ecole'))->id))
            ->latest('id')
            ->limit(min(max($request->integer('limite', 30), 1), 200))
            ->get();

        return response()->json([
            'success' => true,
            'data' => $entrees->map(fn (TenantActivityLog $e) => [
                'le' => ($e->performed_at ?? $e->created_at)?->toIso8601String(),
                'ecole' => $e->tenant?->code,
                'action' => $e->action,
                'description' => $e->description,
                'par' => $e->performedBy?->name,
            ])->all(),
        ]);
    }
}
