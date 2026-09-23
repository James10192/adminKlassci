<?php

namespace App\Http\Controllers\Care;

use App\Domain\Care\Tickets\Models\SupportTicketAttachment;
use App\Filament\Resources\SupportTicketResource;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Le support ouvre une piece jointe depuis le dossier.
 *
 * Le lien est signe et expire : il ne sert qu'a la personne qui l'a obtenu en
 * ouvrant le dossier, et l'acces au dossier est re-verifie a chaque ouverture
 * (un dossier restreint le reste). Une image s'affiche, un PDF se telecharge :
 * le navigateur ne rend jamais un PDF recu d'une ecole dans le contexte du panel.
 */
class PieceJointeSupportController extends Controller
{
    public static function lien(SupportTicketAttachment $piece): string
    {
        return URL::temporarySignedRoute('care.pieces.ouvrir', now()->addMinutes(10), ['piece' => $piece->getKey()]);
    }

    public function __invoke(SupportTicketAttachment $piece): StreamedResponse
    {
        abort_unless(SupportTicketResource::canView($piece->ticket), 404);
        if (! Storage::disk($piece->disk)->exists($piece->path)) {
            Log::error('KLASSCI Care : fichier de pièce jointe introuvable', ['piece' => $piece->getKey(), 'chemin' => $piece->path]);
            abort(404);
        }

        $disposition = $piece->estImage() ? 'inline' : 'attachment';

        return Storage::disk($piece->disk)->response($piece->path, $piece->original_name, [
            'Content-Type' => $piece->mime,
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; img-src 'self'; sandbox",
            'Cache-Control' => 'private, no-store',
        ], $disposition);
    }
}
