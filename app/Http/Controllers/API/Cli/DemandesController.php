<?php

namespace App\Http\Controllers\API\Cli;

use App\Domain\Care\Acces\Services\Capacites;
use App\Domain\Care\Tickets\Enums\StatutTicket;
use App\Domain\Care\Tickets\Models\SupportTicket;
use App\Http\Controllers\API\Cli\Concerns\ResoutEcole;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * klassci admin:demandes, admin:demande — la file KLASSCI Care.
 *
 * Mêmes règles que le panneau : il faut la capacité de voir les demandes, et
 * une demande restreinte (sécurité) n'apparaît qu'à qui peut la lire.
 */
class DemandesController extends Controller
{
    use ResoutEcole;

    public function index(Request $request): JsonResponse
    {
        $membre = $request->user();
        abort_unless(Capacites::accorde($membre, 'support.tickets.view'), 403, 'Votre rôle ne donne pas accès aux demandes.');

        $demandes = SupportTicket::with(['tenant:id,code', 'assignee:id,name'])
            ->when(! Capacites::accorde($membre, 'support.security.view'), fn ($q) => $q->where('is_security_restricted', false))
            ->when($request->boolean('toutes') === false, fn ($q) => $q->ouverts())
            ->when($request->filled('ecole'), fn ($q) => $q->where('tenant_id', $this->ecole($request->string('ecole'))->id))
            ->when($request->filled('statut'), fn ($q) => $q->where('status', StatutTicket::tryFrom(strtoupper($request->string('statut'))) ?? '?'))
            ->latest('id')
            ->limit(min(max($request->integer('limite', 30), 1), 100))
            ->get();

        return response()->json(['success' => true, 'data' => $demandes->map(fn ($d) => $this->resume($d))->all()]);
    }

    public function show(Request $request, string $reference): JsonResponse
    {
        $membre = $request->user();
        abort_unless(Capacites::accorde($membre, 'support.tickets.view'), 403, 'Votre rôle ne donne pas accès aux demandes.');

        $demande = SupportTicket::with(['tenant:id,code', 'assignee:id,name', 'messagesPublics'])
            ->where('reference', $reference)
            ->when(! Capacites::accorde($membre, 'support.security.view'), fn ($q) => $q->where('is_security_restricted', false))
            ->firstOrFail();

        return response()->json(['success' => true, 'data' => $this->resume($demande) + [
            'description' => $demande->description,
            'signale_par' => $demande->reporter_name_snapshot,
            'messages' => $demande->messagesPublics->map(fn ($m) => [
                'auteur' => $m->author_name,
                'le' => $m->created_at?->toIso8601String(),
                'texte' => $m->body,
            ])->values()->all(),
        ]]);
    }

    /** @return array<string, mixed> */
    private function resume(SupportTicket $d): array
    {
        return [
            'reference' => $d->reference,
            'ecole' => $d->tenant?->code,
            'titre' => $d->title,
            'categorie' => $d->customer_category?->libelle(),
            'severite' => $d->severity?->libelle(),
            'statut' => $d->status?->libelle(),
            'assignee' => $d->assignee?->name,
            'le' => $d->created_at?->toIso8601String(),
        ];
    }
}
