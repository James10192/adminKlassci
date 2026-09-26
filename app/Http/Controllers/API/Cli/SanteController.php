<?php

namespace App\Http\Controllers\API\Cli;

use App\Domain\Cli\JournalCli;
use App\Domain\Cli\Presentation;
use App\Http\Controllers\API\Cli\Concerns\ResoutEcole;
use App\Http\Controllers\Controller;
use App\Models\TenantHealthCheck;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;

/** klassci admin:sante, admin:sante:lancer */
class SanteController extends Controller
{
    use ResoutEcole;

    /** Le dernier relevé de chaque contrôle, par école. */
    public function index(Request $request): JsonResponse
    {
        $derniers = TenantHealthCheck::query()
            ->selectRaw('MAX(id) as id')
            ->when($request->filled('ecole'), fn ($q) => $q->where('tenant_id', $this->ecole($request->string('ecole'))->id))
            ->groupBy('tenant_id', 'check_type');

        $releves = TenantHealthCheck::with('tenant:id,code')
            ->whereIn('id', $derniers)
            ->orderBy('tenant_id')
            ->orderBy('check_type')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $releves->map(fn (TenantHealthCheck $r) => Presentation::releve($r))->values()->all(),
        ]);
    }

    /**
     * Six contrôles réseau (HTTP, SSL, SSH…) peuvent dépasser les 30 s
     * qu'accorde l'hébergeur à une requête : ils partent en file, le CLI
     * relit les relevés ensuite.
     */
    public function lancer(Request $request, string $code): JsonResponse
    {
        $ecole = $this->ecole($code);
        Artisan::queue('tenant:health-check', ['tenant' => $ecole->code]);
        JournalCli::consigner('cli_health_checked', 'Vérification de santé demandée depuis le CLI', ecole: $ecole, request: $request);

        return response()->json([
            'success' => true,
            'message' => "Vérification de {$ecole->code} mise en file. Relevés dans une à deux minutes : klassci admin:sante {$ecole->code}",
        ], 202);
    }
}
