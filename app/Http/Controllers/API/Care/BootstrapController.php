<?php

namespace App\Http\Controllers\API\Care;

use App\Http\Controllers\Controller;
use App\Models\TenantFeature;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Ce qu'une instance doit savoir avant d'afficher quoi que ce soit : quelles
 * fonctionnalites KLASSCI Care sont ouvertes pour elle, et les limites de saisie.
 *
 * Une fonctionnalite sans ligne dans tenant_features est fermee : le deploiement
 * se fait ecole par ecole, jamais par defaut.
 */
class BootstrapController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $tenant = $request->attributes->get('care_tenant');
        $connues = config('care.fonctionnalites', []);

        $actives = TenantFeature::query()
            ->where('tenant_id', $tenant->id)
            ->whereIn('feature_key', $connues)
            ->where('is_enabled', true)
            ->pluck('feature_key')
            ->all();

        $l = config('care.limites');

        return response()->json([
            'api_version' => config('care.api_version'),
            'instance' => $tenant->code,
            'fonctionnalites' => collect($connues)->mapWithKeys(fn ($f) => [$f => in_array($f, $actives, true)]),
            // L'instance masque ce que son identifiant ne permet pas (repondre sans support:update).
            'portees' => array_values($request->attributes->get('care_credential')->scopes ?? []),
            'limites' => [
                'description_min' => $l['description_min'],
                'description_max' => $l['description_max'],
                'reponse_min' => $l['reponse_min'],
                'piece_octets_max' => (int) config('care.pieces_jointes.octets_max'),
                'pieces_max' => (int) config('care.pieces_jointes.par_demande_max'),
            ],
        ]);
    }
}
