<?php

namespace App\Http\Controllers\API\Cli;

use App\Domain\Cli\JournalCli;
use App\Domain\Cli\Presentation;
use App\Http\Controllers\API\Cli\Concerns\ResoutEcole;
use App\Http\Controllers\Controller;
use App\Models\TenantBackup;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;

/** klassci admin:sauvegardes, admin:sauvegarder */
class SauvegardesController extends Controller
{
    use ResoutEcole;

    public function index(Request $request): JsonResponse
    {
        $sauvegardes = TenantBackup::with('tenant:id,code')
            ->when($request->filled('ecole'), fn ($q) => $q->where('tenant_id', $this->ecole($request->string('ecole'))->id))
            ->latest('id')
            ->limit(min(max($request->integer('limite', 20), 1), 100))
            ->get();

        return response()->json([
            'success' => true,
            'data' => $sauvegardes->map(fn (TenantBackup $s) => Presentation::sauvegarde($s))->all(),
        ]);
    }

    /** Une sauvegarde complète dépasse le temps d'une requête : elle passe par la file. */
    public function store(Request $request, string $code): JsonResponse
    {
        $ecole = $this->ecole($code);
        $type = $request->validate([
            'type' => ['sometimes', 'in:database_only,files_only,full'],
        ])['type'] ?? 'database_only';

        Artisan::queue('tenant:backup', ['tenant' => $ecole->code, '--type' => $type]);
        JournalCli::consigner('cli_backup_requested', 'Sauvegarde demandée depuis le CLI', ['type' => $type], ecole: $ecole, request: $request);

        return response()->json([
            'success' => true,
            'message' => "Sauvegarde « {$type} » de {$ecole->code} mise en file.",
        ], 202);
    }
}
