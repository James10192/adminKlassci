<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Un identifiant par requete, propage dans les journaux et renvoye en en-tete.
 *
 * Une instance qui appelle le Master transmet le sien : la meme valeur se
 * retrouve alors des deux cotes, et relie une demande de support a la ligne
 * de journal qui l'explique. Une valeur entrante n'est reprise que si elle a
 * la forme d'un ULID ou d'un UUID — sinon on en genere une, pour qu'un appelant
 * ne puisse pas injecter n'importe quoi dans nos journaux.
 */
class AttribuerIdentifiantRequete
{
    private const FORME = '/^([0-9A-HJKMNP-TV-Z]{26}|[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})$/i';

    public function handle(Request $request, Closure $next): Response
    {
        $entrant = (string) $request->headers->get('X-Request-ID', '');
        $id = preg_match(self::FORME, $entrant) ? $entrant : (string) Str::ulid();

        $request->attributes->set('request_id', $id);
        Log::withContext(['request_id' => $id]);

        $reponse = $next($request);
        $reponse->headers->set('X-Request-ID', $id);

        return $reponse;
    }
}
