<?php

namespace App\Http\Controllers\API\Cli;

use App\Domain\Cli\JournalCli;
use App\Domain\Cli\LectureSql;
use App\Domain\Cli\LectureSqlFermee;
use App\Http\Controllers\Controller;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

/** klassci admin:sql — lecture seule, super_admin seulement (capacité cli:sql). */
class SqlController extends Controller
{
    public function __invoke(Request $request, LectureSql $lecture): JsonResponse
    {
        $requete = (string) $request->validate(['requete' => ['required', 'string']])['requete'];

        // Toute requête est tracée, acceptée ou non : c'est un accès direct à la base.
        JournalCli::consigner('cli_sql', 'Requête SQL', ['requete' => mb_substr($requete, 0, 1000)], request: $request);

        try {
            return response()->json(['success' => true, 'data' => $lecture->executer($requete)]);
        } catch (LectureSqlFermee $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 503);
        } catch (InvalidArgumentException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        } catch (QueryException $e) {
            // Le message de la base (table inconnue, faute de syntaxe) sert à
            // corriger la requête ; les identifiants de connexion n'y figurent pas.
            return response()->json(['success' => false, 'message' => $e->getPrevious()?->getMessage() ?? 'Requête refusée par la base.'], 422);
        }
    }
}
