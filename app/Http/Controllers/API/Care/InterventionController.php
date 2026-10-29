<?php

namespace App\Http\Controllers\API\Care;

use App\Domain\Care\Tickets\Models\SupportTicketIntervention;
use App\Http\Controllers\API\Care\Concerns\BorneALInstance;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Minimal routing projection consumed by the authenticated school instance.
 * No student endpoints, no internal ticket notes, no intervention instructions.
 */
final class InterventionController extends Controller
{
    use BorneALInstance;

    public function index(Request $request): JsonResponse
    {
        // Ne jamais annoncer une intervention comme prête tant que le
        // protocole de livraison et les contrôles end-to-end ne sont pas actifs.
        abort_unless(config('care.interventions.routing_preview_enabled', false), 404);
        $tenant = $this->instance($request);
        $page = SupportTicketIntervention::query()
            ->where('tenant_id', $tenant->getKey())
            ->where('status', SupportTicketIntervention::PENDING_ROUTING)
            ->whereHas('ticket', fn ($q) => $q
                ->where('tenant_id', $tenant->getKey())
                ->where('is_security_restricted', false)
                ->whereNotNull('reference')
                ->whereIn('status', \App\Domain\Care\Tickets\Enums\StatutTicket::valeursOuvertes()))
            ->with('ticket:id,reference,tenant_id')
            ->orderBy('id')
            ->paginate(30);

        return response()->json([
            'data' => $page->getCollection()->map(fn (SupportTicketIntervention $i) => [
                'id' => $i->getKey(),
                'reference' => $i->ticket->reference,
                'permission_requise' => $i->required_permission,
                'utilisateur_cible' => $i->target_external_user_id,
                'etat' => $i->status,
                'cree_le' => $i->created_at?->toIso8601String(),
            ])->values()->all(),
            'meta' => [
                'page' => $page->currentPage(),
                'pages' => $page->lastPage(),
                'total' => $page->total(),
            ],
        ]);
    }
}
