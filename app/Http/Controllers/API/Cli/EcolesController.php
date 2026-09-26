<?php

namespace App\Http\Controllers\API\Cli;

use App\Domain\Cli\JournalCli;
use App\Domain\Cli\Presentation;
use App\Http\Controllers\API\Cli\Concerns\LanceArtisan;
use App\Http\Controllers\API\Cli\Concerns\ResoutEcole;
use App\Http\Controllers\Controller;
use App\Models\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;

/** klassci admin:tenants, admin:tenant, admin:stats:maj, admin:scanner */
class EcolesController extends Controller
{
    use LanceArtisan, ResoutEcole;

    public function index(Request $request): JsonResponse
    {
        $ecoles = Tenant::query()
            ->when($request->filled('statut'), fn ($q) => $q->where('status', $request->string('statut')))
            ->when($request->boolean('expirant'), fn ($q) => $q->whereNotNull('subscription_end_date')
                ->whereDate('subscription_end_date', '<=', now()->addDays(30)))
            ->orderBy('code')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $ecoles->map(fn (Tenant $t) => Presentation::tenant($t))->all(),
        ]);
    }

    public function show(string $code): JsonResponse
    {
        $ecole = $this->ecole($code)->load('group');

        return response()->json(['success' => true, 'data' => Presentation::tenant($ecole, detail: true)]);
    }

    public function stats(Request $request, string $code): JsonResponse
    {
        // Le comptage interroge la base de l'école à distance : en file, pour
        // ne pas dépasser les 30 s d'une requête.
        $ecole = $this->ecole($code);
        Artisan::queue('tenant:update-stats', ['tenant' => $ecole->code]);
        JournalCli::consigner('cli_stats_updated', 'Recalcul des statistiques demandé depuis le CLI', ecole: $ecole, request: $request);

        return response()->json([
            'success' => true,
            'message' => "Recalcul de {$ecole->code} mis en file. Chiffres à jour dans une à deux minutes : klassci admin:tenant {$ecole->code}",
        ], 202);
    }

    public function scanner(Request $request): JsonResponse
    {
        $simulation = $request->boolean('simulation', true);
        $sortie = $this->artisan('tenant:discover', $simulation ? ['--dry-run' => true] : []);

        JournalCli::consigner('cli_discover', 'Scan des dossiers', ['simulation' => $simulation], request: $request);

        return response()->json(['success' => $sortie['code'] === 0, 'simulation' => $simulation, 'sortie' => $sortie['texte']]);
    }
}
