<?php

namespace App\Http\Controllers\API\Care;

use App\Domain\Care\Tickets\Actions\JoindrePieceParLEcole;
use App\Domain\Care\Tickets\Exceptions\CleIdempotenceReutilisee;
use App\Domain\Care\Tickets\Exceptions\DemandeClose;
use App\Domain\Care\Tickets\Exceptions\PieceJointeRefusee;
use App\Domain\Care\Tickets\Services\ProjectionClient;
use App\Http\Controllers\API\Care\Concerns\BorneALInstance;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Pieces jointes d'une demande, cote instance. Meme bornage que la lecture :
 * une demande hors de portee rend 404, jamais 403.
 */
class PieceJointeController extends Controller
{
    use BorneALInstance;

    public function __construct(private readonly ProjectionClient $projection)
    {
    }

    public function store(Request $request, string $reference, JoindrePieceParLEcole $joindre): JsonResponse
    {
        $cle = $this->cle($request);
        if ($cle === null) {
            return $this->cleRequise();
        }
        $v = $request->validate([
            // La taille exacte se juge a l'assainissement ; ici on ecarte seulement l'enorme.
            'fichier' => ['required', 'file', 'max:'.intdiv((int) config('care.pieces_jointes.octets_max'), 1024)],
            'author_name' => ['nullable', 'string', 'max:160'],
        ]);
        $filtres = $this->filtres($request);

        $ticket = $this->requete($request, $filtres)->where('reference', $reference)->first();
        abort_if($ticket === null, 404);

        try {
            [, $rejoue] = $joindre->executer(
                $ticket,
                (int) $filtres['reporter'],
                $v['author_name'] ?? null,
                (string) $v['fichier']->get(),
                $v['fichier']->getClientOriginalName(),
                $cle,
            );
        } catch (PieceJointeRefusee $e) {
            return response()->json(['error' => 'attachment_rejected', 'message' => $e->getMessage()], 422);
        } catch (CleIdempotenceReutilisee $e) {
            return response()->json(['error' => 'idempotency_key_reused', 'message' => $e->getMessage()], 422);
        } catch (DemandeClose $e) {
            return response()->json(['error' => 'ticket_closed', 'message' => $e->getMessage()], 409);
        }

        return response()
            ->json($this->projection->detail($ticket->fresh(['messagesPublics', 'piecesJointesPubliques'])), $rejoue ? 200 : 201)
            ->header('Idempotent-Replayed', $rejoue ? 'true' : 'false');
    }

    /** Le contenu d'une piece. L'instance le relaie : l'ecole ne parle jamais au Master. */
    public function show(Request $request, string $reference, int $piece): StreamedResponse
    {
        $ticket = $this->requete($request, $this->filtres($request))->where('reference', $reference)->first();
        abort_if($ticket === null, 404);
        $p = $ticket->piecesJointesPubliques()->whereKey($piece)->first();
        abort_if($p === null, 404);
        if (! Storage::disk($p->disk)->exists($p->path)) {
            Log::error('KLASSCI Care : fichier de pièce jointe introuvable', ['piece' => $p->getKey(), 'chemin' => $p->path]);
            abort(404);
        }

        return Storage::disk($p->disk)->download($p->path, $p->original_name, [
            'Content-Type' => $p->mime,
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; img-src 'self'; sandbox",
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
