<?php

namespace App\Http\Controllers\API\Care\Concerns;

use App\Domain\Care\Tickets\Models\SupportTicket;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Ce qu'une instance peut atteindre : ses demandes, non restreintes, et celles
 * de son rapporteur sauf `scope=school`. L'instance est seule juge de qui peut
 * demander `scope=school` ; le Master borne a l'instance.
 */
trait BorneALInstance
{
    private function filtres(Request $request): array
    {
        return $request->validate([
            'reporter' => ['required', 'integer', 'min:1'],
            'scope' => ['nullable', Rule::in(['mine', 'school'])],
        ]);
    }

    private function requete(Request $request, array $filtres): Builder
    {
        return SupportTicket::query()
            ->pourInstance($this->instance($request))
            ->where('is_security_restricted', false)
            ->when(($filtres['scope'] ?? 'mine') === 'mine',
                fn ($q) => $q->where('reporter_external_id', (int) $filtres['reporter']));
    }

    private function instance(Request $request): Tenant
    {
        return $request->attributes->get('care_tenant');
    }

    private function cle(Request $request): ?string
    {
        $cle = (string) $request->header('Idempotency-Key', '');

        return preg_match('/^[A-Za-z0-9._:-]{8,64}$/', $cle) ? $cle : null;
    }

    private function cleRequise(): JsonResponse
    {
        return response()->json([
            'error' => 'idempotency_key_required',
            'message' => 'En-tête Idempotency-Key requis (8 à 64 caractères).',
        ], 400);
    }
}
