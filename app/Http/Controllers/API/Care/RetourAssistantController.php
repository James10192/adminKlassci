<?php

namespace App\Http\Controllers\API\Care;

use App\Domain\Care\Retours\Actions\EnregistrerRetourAssistant;
use App\Http\Controllers\API\Care\Concerns\BorneALInstance;
use App\Http\Controllers\Controller;
use App\Http\Requests\Care\EnregistrerRetourAssistantRequest;
use Illuminate\Http\JsonResponse;

/**
 * POST /api/v1/support/retours-assistant — un 👍 / 👎 sur une réponse de Nanan.
 *
 * L'instance est celle de l'identifiant (AuthentifierInstance), jamais celle
 * que le corps dirait : le corps n'en porte d'ailleurs aucune.
 */
class RetourAssistantController extends Controller
{
    use BorneALInstance;

    public function store(EnregistrerRetourAssistantRequest $request, EnregistrerRetourAssistant $enregistrer): JsonResponse
    {
        $cle = $this->cle($request);
        if ($cle === null) {
            return $this->cleRequise();
        }

        $resultat = $enregistrer->executer($this->instance($request), $request->validated(), $cle);

        return response()
            ->json(['id' => $resultat->retour->id], $resultat->rejoue ? 200 : 201)
            ->header('Idempotent-Replayed', $resultat->rejoue ? 'true' : 'false');
    }
}
