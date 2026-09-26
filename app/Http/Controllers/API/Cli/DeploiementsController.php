<?php

namespace App\Http\Controllers\API\Cli;

use App\Domain\Cli\JournalCli;
use App\Domain\Cli\Presentation;
use App\Domain\Deploiement\DemanderDeploiement;
use App\Domain\Deploiement\DeploiementDejaDemande;
use App\Http\Controllers\API\Cli\Concerns\ResoutEcole;
use App\Http\Controllers\Controller;
use App\Models\TenantDeployment;
use App\Rules\NomDeBrancheGit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** klassci admin:deploiements, admin:deploiement, admin:deployer */
class DeploiementsController extends Controller
{
    use ResoutEcole;

    public function index(Request $request): JsonResponse
    {
        $deploiements = TenantDeployment::with(['tenant:id,code', 'deployedBy:id,name'])
            ->when($request->filled('ecole'), fn ($q) => $q->where('tenant_id', $this->ecole($request->string('ecole'))->id))
            ->when($request->filled('depuis'), fn ($q) => $q->where('created_at', '>=', $request->date('depuis')))
            ->latest('id')
            ->limit(min(max($request->integer('limite', 20), 1), 100))
            ->get();

        return response()->json([
            'success' => true,
            'data' => $deploiements->map(fn (TenantDeployment $d) => Presentation::deploiement($d))->all(),
        ]);
    }

    public function show(int $id): JsonResponse
    {
        $deploiement = TenantDeployment::with(['tenant:id,code', 'deployedBy:id,name'])->findOrFail($id);

        return response()->json(['success' => true, 'data' => Presentation::deploiement($deploiement, etapes: true)]);
    }

    /**
     * Met le déploiement en file et rend la main : il dure plus longtemps
     * qu'une requête web. Le CLI suit ensuite l'avancement par index/show.
     */
    public function store(Request $request, string $code, DemanderDeploiement $demande): JsonResponse
    {
        $ecole = $this->ecole($code);
        $valide = $request->validate([
            'branche' => ['nullable', 'string', 'max:100', new NomDeBrancheGit()],
            'sans_sauvegarde' => ['sometimes', 'boolean'],
            'sans_migrations' => ['sometimes', 'boolean'],
        ]);

        try {
            $demande->demander(
                $ecole,
                branche: $valide['branche'] ?? null,
                sansSauvegarde: (bool) ($valide['sans_sauvegarde'] ?? false),
                sansMigrations: (bool) ($valide['sans_migrations'] ?? false),
                parMembre: $request->user()->id,
            );
        } catch (DeploiementDejaDemande $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 409);
        }

        JournalCli::consigner('cli_deploy_requested', 'Déploiement demandé depuis le CLI', [
            'branche' => $valide['branche'] ?? $ecole->git_branch,
            'sans_sauvegarde' => (bool) ($valide['sans_sauvegarde'] ?? false),
            'sans_migrations' => (bool) ($valide['sans_migrations'] ?? false),
        ], ecole: $ecole, request: $request);

        return response()->json([
            'success' => true,
            'message' => "Déploiement de {$ecole->code} mis en file. Il démarre à la prochaine minute.",
            'ecole' => $ecole->code,
            'demande_le' => now()->toIso8601String(),
        ], 202);
    }
}
