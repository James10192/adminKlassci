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
 * charge utile cachee derriere une image valide. L'orientation EXIF est
 * appliquee aux pixels avant d'etre perdue, sinon une photo de telephone
 * arriverait couchee.
 *
 * Un PDF ne se re-encode pas : `InspectionPdf` le lit, flux compresses compris,
 * et refuse ce qu'elle ne sait pas decoder. Le PDF se telecharge toujours (jamais
 * rendu dans le panel), et le support l'ouvre en le sachant.
 */
class AssainissementPieceJointe
{
    public function __construct(private readonly InspectionPdf $inspection)
    {
    }

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
            : $this->image($contenu, $mime, $extension, $nom, (int) $c['cote_max_px'], (int) $c['pixels_max']);
    }

    private function pdf(string $contenu, string $nom): FichierAssaini
    {
        if (! str_starts_with($contenu, '%PDF-')) {
            throw new PieceJointeRefusee('Ce PDF est illisible.');
        }
        $this->inspection->verifier($contenu, (int) config('care.pieces_jointes.pdf_inflation_max_octets'));

        return new FichierAssaini($contenu, 'application/pdf', 'pdf', $nom);
    }

    private function image(string $contenu, string $mime, string $extension, string $nom, int $coteMax, int $pixelsMax): FichierAssaini
    {
        $taille = @getimagesizefromstring($contenu);
        // Refuser avant de decoder : une bombe de decompression tient en quelques Ko,
        // et la libgd du systeme alloue hors de memory_limit.
        if ($taille === false || $taille[0] < 1 || $taille[1] < 1 || $taille[0] * $taille[1] > $pixelsMax) {
            throw new PieceJointeRefusee('Cette image est illisible ou trop grande.');
        }
        $source = @imagecreatefromstring($contenu);
        if ($source === false) {
            throw new PieceJointeRefusee('Cette image est illisible.');
        }

        // Reduire avant de tourner : la rotation copie l'image, autant copier la petite.
        [$l, $h] = [imagesx($source), imagesy($source)];
        $ratio = min(1, $coteMax / max($l, $h));
        if ($ratio < 1) {
            $reduite = imagescale($source, max(1, (int) round($l * $ratio)), max(1, (int) round($h * $ratio)));
            imagedestroy($source);
            $source = $reduite;
        }
        if ($mime === 'image/jpeg') {
            $source = $this->redresser($source, $contenu);
        }
        [$l, $h] = [imagesx($source), imagesy($source)];

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

    /**
     * Applique l'orientation EXIF aux pixels. Le re-encodage perd l'EXIF : sans
     * ceci, une photo prise en portrait au telephone arrive couchee.
     */
    private function redresser(\GdImage $image, string $contenu): \GdImage
    {
        if (! function_exists('exif_read_data')) {
            // Sans l'extension exif, une photo de telephone arrive couchee : le dire.
            \Illuminate\Support\Facades\Log::warning('care.piece_jointe.exif_absent');

            return $image;
        }
        $exif = @exif_read_data('data://image/jpeg;base64,'.base64_encode($contenu));
        $orientation = (int) ($exif['Orientation'] ?? 1);

        // 2..8 : miroir horizontal, 180°, miroir vertical, transposition, 90° horaire,
        // transverse, 90° anti-horaire. imagerotate tourne dans le sens anti-horaire.
        $rotation = [3 => 180, 4 => 180, 5 => 270, 6 => 270, 7 => 90, 8 => 90][$orientation] ?? 0;
        if ($rotation !== 0) {
            $tournee = imagerotate($image, $rotation, 0);
            if ($tournee !== false) {
                imagedestroy($image);
                $image = $tournee;
            }
        }
        if (in_array($orientation, [2, 4, 5, 7], true)) {
            imageflip($image, IMG_FLIP_HORIZONTAL);
        }

        return $image;
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
