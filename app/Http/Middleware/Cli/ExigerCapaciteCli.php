<?php

namespace App\Http\Middleware\Cli;

use App\Domain\Cli\CapacitesCli;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Deux conditions pour passer : le jeton porte la capacité, et le rôle actuel
 * du membre la porte encore. Le jeton seul ne suffit pas : il a pu être émis
 * avant une rétrogradation.
 */
class ExigerCapaciteCli
{
    public function handle(Request $request, Closure $next, string $capacite): Response
    {
        $membre = $request->user();

        if (! $membre instanceof User || ! $membre->tokenCan($capacite) || ! CapacitesCli::autorise($membre, $capacite)) {
            return response()->json([
                'success' => false,
                'message' => "Votre rôle ou votre jeton ne permet pas cette action ({$capacite}).",
            ], 403);
        }

        return $next($request);
    }
}
