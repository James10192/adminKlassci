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
 * Un PDF ne se re-encode pas sans outil que l'hebergement n'a pas. Il est
 * refuse quand il porte un contenu actif REPERABLE (JavaScript, lancement,
 * fichier embarque) : noms echappes (#xx) decodes, flux FlateDecode inflates
 * dans une limite de taille. C'est une heuristique, pas une garantie : un flux
 * sous un autre filtre (LZW, ASCII85, chaine de filtres) n'est pas relu. Le PDF
 * se telecharge toujours (jamais rendu dans le panel), et le support l'ouvre
 * en le sachant.
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
            : $this->image($contenu, $mime, $extension, $nom, (int) $c['cote_max_px'], (int) $c['pixels_max']);
    }

    private function pdf(string $contenu, string $nom): FichierAssaini
    {
        if (! str_starts_with($contenu, '%PDF-')) {
            throw new PieceJointeRefusee('Ce PDF est illisible.');
        }
        foreach ($this->textesDuPdf($contenu) as $texte) {
            if ($this->porteUnContenuActif($texte)) {
                throw new PieceJointeRefusee('Ce PDF contient des éléments actifs et ne peut pas être joint. Envoyez une capture d\'écran à la place.');
            }
        }

        return new FichierAssaini($contenu, 'application/pdf', 'pdf', $nom);
    }

    /**
     * Le fichier lui-meme, puis chaque flux FlateDecode inflate : depuis PDF 1.5,
     * les objets vivent souvent dans des flux compresses (/ObjStm), ou un
     * /OpenAction ne se voit pas en clair. Le budget d'inflation borne la bombe
     * de decompression ; le depasser est un refus, pas un accord.
     */
    private function textesDuPdf(string $contenu): \Generator
    {
        yield $contenu;

        $budget = (int) config('care.pieces_jointes.pdf_inflation_max_octets');
        preg_match_all('/stream\r?\n(.*?)endstream/s', $contenu, $flux);
        foreach ($flux[1] as $brut) {
            $inflate = @gzuncompress($brut, $budget + 1);
            if ($inflate === false) {
                $inflate = @gzinflate($brut, $budget + 1);
            }
            if ($inflate === false) {
                continue; // un autre filtre, ou des octets d'image : non relu (voir la classe).
            }
            $budget -= strlen($inflate);
            if ($budget < 0) {
                throw new PieceJointeRefusee('Ce PDF est trop complexe pour être vérifié. Envoyez une capture d\'écran à la place.');
            }
            yield $inflate;
        }
    }

    private function porteUnContenuActif(string $texte): bool
    {
        // Un nom PDF peut s'ecrire /J#61vaScript : on decode les echappements avant de chercher.
        $texte = (string) preg_replace_callback('/#([0-9A-Fa-f]{2})/', fn ($m) => chr(hexdec($m[1])), $texte);
        foreach (self::PDF_ACTIF as $marqueur) {
            // Le marqueur doit etre un nom complet : /JS ne doit pas refuser /JSmith.
            if (preg_match('#'.preg_quote($marqueur, '#').'(?![A-Za-z0-9])#', $texte)) {
                return true;
            }
        }

        return false;
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
        if ($mime === 'image/jpeg') {
            $source = $this->redresser($source, $contenu);
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

    /**
     * Applique l'orientation EXIF aux pixels. Le re-encodage perd l'EXIF : sans
     * ceci, une photo prise en portrait au telephone arrive couchee.
     */
    private function redresser(\GdImage $image, string $contenu): \GdImage
    {
        if (! function_exists('exif_read_data')) {
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
