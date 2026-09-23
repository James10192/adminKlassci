<?php

namespace App\Domain\Care\Tickets\Services;

use App\Domain\Care\Tickets\DTO\FichierAssaini;
use App\Domain\Care\Tickets\Exceptions\PieceJointeRefusee;

/**
 * Ce qui entre dans KLASSCI Care comme piece jointe.
 *
 * Le type se lit sur le CONTENU, jamais sur l'extension ni sur l'en-tete
 * envoye : les deux se choisissent. Les images sont decodees puis re-encodees,
 * ce qui ne garde que les pixels — plus d'EXIF, plus de position GPS, plus de
 * charge utile cachee derriere une image valide. Un PDF ne se re-encode pas
 * sans outil que l'hebergement n'a pas : il est refuse des qu'il porte du
 * contenu actif (JavaScript, lancement, fichier embarque).
 */
class AssainissementPieceJointe
{
    /** Marqueurs de contenu actif dans un PDF. Un faux refus coute un courriel ; un faux accord, un poste. */
    private const PDF_ACTIF = ['/JavaScript', '/JS', '/Launch', '/EmbeddedFile', '/OpenAction', '/AA', '/RichMedia', '/XFA'];

    public function assainir(string $contenu, ?string $nomEnvoye): FichierAssaini
    {
        $c = config('care.pieces_jointes');
        if ($contenu === '') {
            throw new PieceJointeRefusee('Le fichier est vide.');
        }
        if (strlen($contenu) > $c['octets_max']) {
            throw new PieceJointeRefusee('Le fichier dépasse '.intdiv($c['octets_max'], 1024 * 1024).' Mo.');
        }

        $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($contenu) ?: '';
        $extension = $c['types'][$mime] ?? null;
        if ($extension === null) {
            throw new PieceJointeRefusee('Seules les images (PNG, JPEG, WebP) et les PDF sont acceptés.');
        }

        $nom = $this->nom($nomEnvoye, $extension);

        return $mime === 'application/pdf'
            ? $this->pdf($contenu, $nom)
            : $this->image($contenu, $mime, $extension, $nom, (int) $c['cote_max_px']);
    }

    private function pdf(string $contenu, string $nom): FichierAssaini
    {
        if (! str_starts_with($contenu, '%PDF-')) {
            throw new PieceJointeRefusee('Ce PDF est illisible.');
        }
        foreach (self::PDF_ACTIF as $marqueur) {
            // Le marqueur doit etre un nom complet : /JS ne doit pas refuser /JSmith.
            if (preg_match('#'.preg_quote($marqueur, '#').'(?![A-Za-z0-9])#', $contenu)) {
                throw new PieceJointeRefusee('Ce PDF contient des éléments actifs et ne peut pas être joint. Envoyez une capture d\'écran à la place.');
            }
        }

        return new FichierAssaini($contenu, 'application/pdf', 'pdf', $nom);
    }

    private function image(string $contenu, string $mime, string $extension, string $nom, int $coteMax): FichierAssaini
    {
        $taille = @getimagesizefromstring($contenu);
        // Refuser avant de decoder : une bombe de decompression tient en quelques Ko.
        if ($taille === false || $taille[0] < 1 || $taille[1] < 1 || $taille[0] * $taille[1] > 40_000_000) {
            throw new PieceJointeRefusee('Cette image est illisible ou trop grande.');
        }
        $source = @imagecreatefromstring($contenu);
        if ($source === false) {
            throw new PieceJointeRefusee('Cette image est illisible.');
        }

        [$l, $h] = [imagesx($source), imagesy($source)];
        $ratio = min(1, $coteMax / max($l, $h));
        if ($ratio < 1) {
            $reduite = imagescale($source, max(1, (int) round($l * $ratio)), max(1, (int) round($h * $ratio)));
            imagedestroy($source);
            $source = $reduite;
            [$l, $h] = [imagesx($source), imagesy($source)];
        }

        ob_start();
        $ok = match ($mime) {
            'image/png' => imagesavealpha($source, true) && imagepng($source, null, 6),
            'image/jpeg' => imagejpeg($source, null, 85),
            'image/webp' => imagewebp($source, null, 85),
        };
        $sortie = (string) ob_get_clean();
        imagedestroy($source);

        if (! $ok || $sortie === '') {
            throw new PieceJointeRefusee('Cette image n\'a pas pu être traitée.');
        }

        return new FichierAssaini($sortie, $mime, $extension, $nom, $l, $h);
    }

    /** Un nom montrable : sans chemin, sans caracteres de controle, avec la vraie extension. */
    private function nom(?string $envoye, string $extension): string
    {
        $base = pathinfo(basename(str_replace('\\', '/', (string) $envoye)), PATHINFO_FILENAME);
        $base = trim((string) preg_replace('/[^\p{L}\p{N} ._()-]+/u', '', $base));
        $base = mb_substr($base, 0, 120);

        return ($base !== '' ? $base : 'piece-jointe').'.'.$extension;
    }
}
