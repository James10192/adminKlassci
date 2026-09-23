<?php

namespace App\Http\Controllers\API\Care;

use App\Domain\Care\Tickets\Actions\CreerTicket;
use App\Domain\Care\Tickets\Actions\RepondreParLEcole;
use App\Domain\Care\Tickets\DTO\SoumissionTicket;
use App\Domain\Care\Tickets\Exceptions\CleIdempotenceReutilisee;
use App\Domain\Care\Tickets\Exceptions\DemandeClose;
use App\Domain\Care\Tickets\Models\SupportTicket;
use App\Domain\Care\Tickets\Services\ProjectionClient;
use App\Http\Controllers\API\Care\Concerns\BorneALInstance;
use App\Http\Controllers\Controller;
use App\Http\Requests\Care\CreerTicketRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * API /api/v1/support/tickets — ce que les instances voient de KLASSCI Care.
 *
 * Chaque lecture est bornee deux fois : a l'instance de l'identifiant, puis au
 * rapporteur (scope=mine) ou a l'ecole entiere (scope=school). L'instance est
 * seule juge de qui a le droit de demander scope=school : elle verifie sa
 * permission avant d'appeler. Le Master, lui, garantit qu'aucune ecole ne lit
 * celle d'une autre.
 */
class TicketController extends Controller
{
    use BorneALInstance;

    public function __construct(private readonly ProjectionClient $projection)
    {
    }

    public function store(CreerTicketRequest $request, CreerTicket $creer): JsonResponse
    {
        $cle = $this->cle($request);
        if ($cle === null) {
            return $this->cleRequise();
        }

        try {
            $resultat = $creer->executer($this->instance($request), SoumissionTicket::depuisRequete($request->donnees()), $cle);
        } catch (CleIdempotenceReutilisee $e) {
            return response()->json(['error' => 'idempotency_key_reused', 'message' => $e->getMessage()], 422);
        }

        return response()
            ->json($this->projection->resume($resultat->ticket), $resultat->rejoue ? 200 : 201)
            ->header('Idempotent-Replayed', $resultat->rejoue ? 'true' : 'false');
    }

    public function index(Request $request): JsonResponse
    {
        $filtres = $this->filtres($request);

        $page = $this->requete($request, $filtres)
            ->with(['messagesPublics', 'piecesJointesPubliques'])
            ->when($request->boolean('ouverts'), fn ($q) => $q->ouverts())
            ->latest('updated_at')
            ->paginate(20);

        return response()->json([
            'data' => $page->getCollection()->map(fn ($t) => $this->projection->resume($t))->values(),
            'meta' => ['page' => $page->currentPage(), 'pages' => $page->lastPage(), 'total' => $page->total()],
        ]);
    }

    public function show(Request $request, string $reference): JsonResponse
    {
        $filtres = $this->filtres($request);

        $ticket = $this->requete($request, $filtres)
            ->with(['messagesPublics', 'piecesJointesPubliques'])
            ->where('reference', $reference)
            ->first();

        // 404 et non 403 : ne pas confirmer qu'une reference existe ailleurs.
        abort_if($ticket === null, 404);

        return response()->json($this->projection->detail($ticket));
    }

    /**
     * L'ecole repond sur une demande qu'elle a le droit de lire : meme bornage
     * que show(), donc une reference d'une autre ecole rend 404.
     */
    public function repondre(Request $request, string $reference, RepondreParLEcole $repondre): JsonResponse
    {
        $cle = $this->cle($request);
        if ($cle === null) {
            return $this->cleRequise();
        }
        $l = config('care.limites');
        $v = $request->validate([
            'body' => ['required', 'string', 'min:'.$l['reponse_min'], 'max:'.$l['description_max']],
            // Le nom affiche sous la reponse ; qui repond est le rapporteur de la requete.
            'author_name' => ['nullable', 'string', 'max:160'],
        ]);
        $filtres = $this->filtres($request);

        $ticket = $this->requete($request, $filtres)->where('reference', $reference)->first();
        abort_if($ticket === null, 404);

        try {
            $rejoue = $repondre->executer($ticket, (int) $filtres['reporter'], $v['author_name'] ?? null, $v['body'], $cle);
        } catch (CleIdempotenceReutilisee $e) {
            return response()->json(['error' => 'idempotency_key_reused', 'message' => $e->getMessage()], 422);
        } catch (DemandeClose $e) {
            return response()->json(['error' => 'ticket_closed', 'message' => $e->getMessage()], 409);
        }

        return response()
            ->json($this->projection->detail($ticket->fresh(['messagesPublics', 'piecesJointesPubliques'])), $rejoue ? 200 : 201)
            ->header('Idempotent-Replayed', $rejoue ? 'true' : 'false');
    }
}
