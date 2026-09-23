<?php

namespace App\Domain\Care\Tickets\Services;

use App\Domain\Care\Tickets\Exceptions\PieceJointeRefusee;

/**
 * Cherche un contenu actif dans un PDF, en refusant ce qu'elle ne sait pas lire.
 *
 * Un PDF ne se re-encode pas sans un outil que l'hebergement n'a pas. On lit donc
 * le fichier en clair, puis chaque flux compresse, et on refuse des qu'un marqueur
 * de contenu actif apparait. La regle qui compte est l'inverse du premier jet :
 * un flux qu'on ne sait pas decoder n'est PAS accepte par defaut. Seuls passent
 * les flux sans filtre (deja lus en clair), les flux d'image (DCT, JPX, CCITT,
 * JBIG2 : des pixels, pas des objets) et les flux FlateDecode effectivement
 * inflates. Tout autre filtre, toute chaine inconnue, tout PDF chiffre : refus.
 *
 * Un faux refus coute un courriel ; un faux accord, un poste.
 *
 * Ce n'est pas un analyseur PDF. Trois choix le rendent sur malgre ca :
 * - chaque mot-cle `stream` du fichier est examine, meme dans une chaine ou un
 *   commentaire, et le curseur n'avance jamais au-dela du debut des donnees d'un
 *   flux : un faux flux ne peut pas faire sauter un vrai ;
 * - les filtres sont lus sur tout le texte depuis le flux precedent, pas sur un
 *   dictionnaire reconstruit : une chaine qui imite `/Filter` ajoute un filtre au
 *   lot, elle ne peut pas en retirer un ;
 * - l'inflation suit le flux compresse jusqu'a sa fin reelle, pas jusqu'au
 *   premier `endstream` litteral, qu'un bloc stocke peut contenir.
 */
class InspectionPdf
{
    /** Marqueurs de contenu actif, noms complets (voir `porteUnContenuActif`). */
    private const ACTIF = ['/JavaScript', '/JS', '/Launch', '/EmbeddedFile', '/OpenAction', '/AA', '/RichMedia', '/XFA'];

    private const FLATE = ['FlateDecode', 'Fl'];

    private const IMAGE = ['DCTDecode', 'DCT', 'JPXDecode', 'CCITTFaxDecode', 'CCF', 'JBIG2Decode'];

    private const REFUS = 'Ce PDF contient des éléments actifs et ne peut pas être joint. Envoyez une capture d\'écran à la place.';

    private const ILLISIBLE = 'Ce PDF ne peut pas être vérifié. Envoyez une capture d\'écran à la place.';

    public function verifier(string $contenu, int $budgetInflation): void
    {
        $clair = $this->sansEchappements($contenu);
        if ($this->contient($clair, '/Encrypt')) {
            throw new PieceJointeRefusee('Ce PDF est protégé et ne peut pas être vérifié. Envoyez une capture d\'écran à la place.');
        }
        $this->refuserSiActif($clair);

        preg_match_all('/(?<!end)stream(?:\r\n|\r|\n)/', $contenu, $flux, PREG_OFFSET_CAPTURE);
        $curseur = 0;
        foreach ($flux[0] as [$motCle, $position]) {
            if ($position < $curseur) {
                continue;
            }
            $debutDonnees = $position + strlen($motCle);
            $entete = $this->sansEchappements(substr($contenu, $curseur, $position - $curseur));
            $curseur = $debutDonnees;

            $filtres = $this->filtres($entete);
            if ($filtres === [] || array_diff($filtres, self::IMAGE) === []) {
                continue; // sans filtre : deja lu en clair ; image : des pixels.
            }
            if (array_intersect($filtres, self::FLATE) === [] || array_diff($filtres, self::FLATE, self::IMAGE) !== []) {
                throw new PieceJointeRefusee(self::ILLISIBLE);
            }

            $texte = $this->inflater($contenu, $debutDonnees, $budgetInflation);
            $budgetInflation -= strlen($texte);
            $this->refuserSiActif($this->sansEchappements($this->sansPredicteur($texte, $entete)));
        }
    }

    /**
     * Les noms de filtre annonces dans le texte. Une valeur indirecte (`/Filter 5 0 R`)
     * ne se resout pas ici : elle rend le flux illisible, donc refuse. Les noms
     * pointes (`/Adobe.PPKLite`) sont des gestionnaires de signature, pas des filtres.
     *
     * @return list<string>
     */
    private function filtres(string $entete): array
    {
        $annonces = preg_match_all('#/Filter(?![A-Za-z0-9])#', $entete);
        preg_match_all('#/Filter\s*(\[[^\]]*\]|/[^\s/\[\]<>()]+)#', $entete, $valeurs);
        if (count($valeurs[1]) !== $annonces) {
            throw new PieceJointeRefusee(self::ILLISIBLE);
        }

        $noms = [];
        foreach ($valeurs[1] as $valeur) {
            preg_match_all('#/([^\s/\[\]<>()]+)#', $valeur, $m);
            $noms = [...$noms, ...array_filter($m[1], fn ($n) => ! str_contains($n, '.'))];
        }

        return array_values(array_unique($noms));
    }

    /** Inflate le flux commencant a `$debut`, jusqu'a sa fin reelle, dans le budget. */
    private function inflater(string $contenu, int $debut, int $budget): string
    {
        foreach ([ZLIB_ENCODING_DEFLATE, ZLIB_ENCODING_RAW] as $encodage) {
            $contexte = inflate_init($encodage);
            $sortie = '';
            for ($pos = $debut; $pos < strlen($contenu); $pos += 8192) {
                $morceau = @inflate_add($contexte, substr($contenu, $pos, 8192), ZLIB_SYNC_FLUSH);
                if ($morceau === false) {
                    break;
                }
                $sortie .= $morceau;
                if (strlen($sortie) > $budget) {
                    throw new PieceJointeRefusee('Ce PDF est trop complexe pour être vérifié. Envoyez une capture d\'écran à la place.');
                }
                if (inflate_get_status($contexte) === ZLIB_STREAM_END) {
                    return $sortie;
                }
            }
        }

        throw new PieceJointeRefusee(self::ILLISIBLE);
    }

    /**
     * Un predicteur PNG (/Predictor 10 a 15) code chaque octet par difference :
     * un nom n'y apparait plus en clair. On le defait ; tout autre predicteur, ou
     * des parametres ambigus, rendent le flux illisible.
     */
    private function sansPredicteur(string $texte, string $entete): string
    {
        $predicteur = $this->parametre($entete, 'Predictor', 1);
        if ($predicteur <= 1) {
            return $texte;
        }
        if ($predicteur < 10) {
            throw new PieceJointeRefusee(self::ILLISIBLE);
        }

        $bits = $this->parametre($entete, 'Colors', 1) * $this->parametre($entete, 'BitsPerComponent', 8);
        $parPixel = max(1, intdiv($bits + 7, 8));
        $largeur = intdiv($this->parametre($entete, 'Columns', 1) * $bits + 7, 8);
        if ($largeur < 1 || strlen($texte) % ($largeur + 1) !== 0) {
            throw new PieceJointeRefusee(self::ILLISIBLE);
        }

        $sortie = '';
        $precedente = str_repeat("\0", $largeur);
        foreach (str_split($texte, $largeur + 1) as $ligne) {
            $courante = '';
            for ($i = 0; $i < $largeur; $i++) {
                $a = $i >= $parPixel ? ord($courante[$i - $parPixel]) : 0;
                $b = ord($precedente[$i]);
                $c = $i >= $parPixel ? ord($precedente[$i - $parPixel]) : 0;
                $courante .= chr((ord($ligne[$i + 1]) + match (ord($ligne[0])) {
                    0 => 0,
                    1 => $a,
                    2 => $b,
                    3 => intdiv($a + $b, 2),
                    4 => $this->paeth($a, $b, $c),
                    default => throw new PieceJointeRefusee(self::ILLISIBLE),
                }) & 0xFF);
            }
            $sortie .= $courante;
            $precedente = $courante;
        }

        return $sortie;
    }

    private function paeth(int $a, int $b, int $c): int
    {
        $p = $a + $b - $c;
        [$pa, $pb, $pc] = [abs($p - $a), abs($p - $b), abs($p - $c)];

        return $pa <= $pb && $pa <= $pc ? $a : ($pb <= $pc ? $b : $c);
    }

    /** Un parametre entier du dictionnaire ; deux valeurs differentes le rendent ambigu. */
    private function parametre(string $entete, string $nom, int $defaut): int
    {
        preg_match_all('#/'.$nom.'\s+(\d+)#', $entete, $m);
        $valeurs = array_unique(array_map('intval', $m[1]));
        if (count($valeurs) > 1) {
            throw new PieceJointeRefusee(self::ILLISIBLE);
        }

        return $valeurs === [] ? $defaut : reset($valeurs);
    }

    private function refuserSiActif(string $texte): void
    {
        foreach (self::ACTIF as $marqueur) {
            if ($this->contient($texte, $marqueur)) {
                throw new PieceJointeRefusee(self::REFUS);
            }
        }
    }

    /** Le marqueur doit etre un nom complet : /JS ne doit pas refuser /JSmith. */
    private function contient(string $texte, string $nom): bool
    {
        return (bool) preg_match('#'.preg_quote($nom, '#').'(?![A-Za-z0-9])#', $texte);
    }

    /** Un nom PDF peut s'ecrire /J#61vaScript : on decode les echappements avant de chercher. */
    private function sansEchappements(string $texte): string
    {
        return (string) preg_replace_callback('/#([0-9A-Fa-f]{2})/', fn ($m) => chr(hexdec($m[1])), $texte);
    }
}
