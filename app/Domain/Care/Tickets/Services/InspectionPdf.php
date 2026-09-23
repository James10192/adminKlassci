<?php

namespace App\Domain\Care\Tickets\Services;

use App\Domain\Care\Tickets\Exceptions\PieceJointeRefusee;

/**
 * Cherche un contenu actif dans un PDF, en refusant ce qu'elle ne sait pas lire.
 *
 * Un PDF ne se re-encode pas sans un outil que l'hebergement n'a pas. Un contenu
 * actif s'annonce par un NOM dans un dictionnaire (`/OpenAction`, `/JS`…), et un
 * dictionnaire ne vit qu'a deux endroits : dans le texte du fichier, ou dans un
 * flux d'objets compresse. `AnalyseurPdf` lit le premier et remet chaque flux ;
 * ici on decide de chacun, sur son propre dictionnaire :
 * - une image (`/Subtype /Image`) ne porte que des pixels : sautee, quel que soit
 *   son filtre ;
 * - un flux sans filtre est lu tel quel ;
 * - un flux FlateDecode est inflate jusqu'a sa fin, predicteur PNG defait, et lu ;
 * - tout le reste — autre filtre, chaine de filtres, valeur indirecte, fichier
 *   externe, PDF chiffre — est refuse comme illisible.
 *
 * Un faux refus coute un courriel ; un faux accord, un poste. Liens `/URI` exclus
 * a dessein : presque tout PDF en porte, et un lien s'ouvre sur un clic, pas seul.
 */
class InspectionPdf
{
    /**
     * Noms qui declenchent une action ou embarquent un contenu. `/OpenAction` n'y
     * est pas : sa forme la plus courante, un tableau, n'est qu'une page d'ouverture
     * (mPDF l'ecrit sur chaque document). Elle est jugee sur sa valeur.
     */
    private const ACTIF = ['JavaScript', 'JS', 'Launch', 'EmbeddedFile', 'AA', 'RichMedia', 'XFA',
        'SubmitForm', 'ImportData', 'GoToE', 'Rendition'];

    private const REFUS = 'Ce PDF contient des éléments actifs et ne peut pas être joint. Envoyez une capture d\'écran à la place.';

    private const ILLISIBLE = 'Ce PDF ne peut pas être vérifié. Envoyez une capture d\'écran à la place.';

    private const PROTEGE = 'Ce PDF est protégé et ne peut pas être vérifié. Envoyez une capture d\'écran à la place.';

    public function verifier(string $contenu, int $budgetInflation): void
    {
        $xref = ['positions' => [], 'compresses' => [], 'flux' => 0];
        $lus = [];
        $structure = (new AnalyseurPdf($contenu))->parcourir(
            fn (string $nom) => $this->juger($nom),
            function (array $dict, int $debut, ?int $objet) use ($contenu, &$budgetInflation, &$xref, &$lus): int {
                $fin = $debut + $this->longueur($dict, $contenu);
                if ($fin > strlen($contenu)) {
                    $this->refuser();
                }
                if ($this->estUneImage($dict)) {
                    return $fin;
                }
                $texte = $this->decoder(substr($contenu, $debut, $fin - $debut), $dict, $budgetInflation);
                $budgetInflation -= strlen($texte);
                $this->scanner($texte);
                if ($objet !== null) {
                    $lus[$objet] = true;
                }
                if ($this->nom($dict['Type'] ?? null) === 'XRef') {
                    $this->lireFluxXref($texte, $dict, $xref);
                }

                return $fin;
            },
            fn (array $dict) => $this->jugerOuverture($dict['OpenAction'] ?? null),
        );

        $this->verifierXref($contenu, $structure, $xref, $lus);
    }

    /**
     * Un lecteur atteint les objets par la table xref, pas en parcourant le
     * fichier. Chaque entree doit donc tomber sur un objet que l'analyseur a lu,
     * et chaque objet compresse vivre dans un flux decode ici. Sinon un objet
     * pourrait se loger dans les donnees d'une image, invisible pour nous.
     *
     * @param  array{objets: array<int, int>, xref: list<int>}  $structure
     * @param  array{positions: list<int>, compresses: list<int>, flux: int}  $xref
     * @param  array<int, true>  $lus
     */
    private function verifierXref(string $contenu, array $structure, array $xref, array $lus): void
    {
        if ($structure['xref'] === [] && $xref['flux'] === 0) {
            $this->refuser();
        }
        foreach ([...$structure['xref'], ...$xref['positions']] as $position) {
            $position += strspn($contenu, "\0\t\n\f\r ", $position);
            if (! isset($structure['objets'][$position])) {
                $this->refuser();
            }
        }
        foreach ($xref['compresses'] as $fluxDObjets) {
            if (! isset($lus[$fluxDObjets])) {
                $this->refuser();
            }
        }
    }

    /**
     * Les entrees d'un flux xref (`/W`, `/Index`) : type 1, position d'un objet ;
     * type 2, numero du flux d'objets qui le contient.
     *
     * @param  array{positions: list<int>, compresses: list<int>, flux: int}  $xref
     */
    private function lireFluxXref(string $texte, array $dict, array &$xref): void
    {
        $largeurs = $this->entiers($dict['W'] ?? null);
        $taille = $dict['Size'] ?? null;
        if (count($largeurs) !== 3 || ! is_int($taille)) {
            $this->refuser();
        }
        $index = array_key_exists('Index', $dict) ? $this->entiers($dict['Index']) : [0, $taille];
        $ligne = array_sum($largeurs);
        if ($ligne < 1 || count($index) % 2 !== 0) {
            $this->refuser();
        }

        $xref['flux']++;
        $pos = 0;
        foreach (array_chunk($index, 2) as [, $nombre]) {
            for ($i = 0; $i < $nombre; $i++, $pos += $ligne) {
                if ($pos + $ligne > strlen($texte)) {
                    $this->refuser();
                }
                $champs = [];
                $curseur = $pos;
                foreach ($largeurs as $largeur) {
                    $champs[] = $largeur === 0 ? null : (int) hexdec(bin2hex(substr($texte, $curseur, $largeur)));
                    $curseur += $largeur;
                }
                match ($champs[0] ?? 1) {
                    1 => $xref['positions'][] = (int) $champs[1],
                    2 => $xref['compresses'][] = (int) $champs[1],
                    default => null,
                };
            }
        }
    }

    /** @return list<int> */
    private function entiers(mixed $valeur): array
    {
        if (! is_array($valeur) || ($valeur['t'] ?? null) !== 'tableau') {
            $this->refuser();
        }

        return array_map(fn ($n) => is_int($n) && $n >= 0 ? $n : $this->refuser(), $valeur['v']);
    }

    /** Le texte d'un flux : tel quel sans filtre, inflate en FlateDecode, refuse autrement. */
    private function decoder(string $donnees, array $dict, int $budget): string
    {
        if (array_key_exists('F', $dict)) {
            $this->refuser(); // donnees dans un fichier externe
        }
        $filtres = $this->filtres($dict['Filter'] ?? null);
        if ($filtres === []) {
            return $donnees;
        }
        if ($filtres !== ['FlateDecode'] && $filtres !== ['Fl']) {
            $this->refuser();
        }

        return $this->sansPredicteur(
            $this->inflater($donnees, $budget),
            $this->parametresDeDecodage($dict['DecodeParms'] ?? $dict['DP'] ?? null),
        );
    }

    /** Le texte d'un flux lu ou inflate : on y cherche les noms, faute de pouvoir le parcourir. */
    private function scanner(string $texte): void
    {
        $texte = (string) preg_replace_callback('/#([0-9A-Fa-f]{2})/', fn ($m) => chr(hexdec($m[1])), $texte);
        preg_match_all('#/([^\s()<>\[\]{}/%]+)#', $texte, $noms);
        foreach (array_unique($noms[1]) as $nom) {
            $this->juger($nom);
        }
        if (preg_match('#/OpenAction(?!\s*\[)#', $texte)) {
            throw new PieceJointeRefusee(self::REFUS);
        }
    }

    /** Une page d'ouverture (tableau) est inoffensive ; une action, ou ce qu'on ne voit pas, non. */
    private function jugerOuverture(mixed $valeur): void
    {
        if ($valeur !== null && ($valeur['t'] ?? null) !== 'tableau') {
            throw new PieceJointeRefusee(self::REFUS);
        }
    }

    private function juger(string $nom): void
    {
        if ($nom === 'Encrypt') {
            throw new PieceJointeRefusee(self::PROTEGE);
        }
        if (in_array($nom, self::ACTIF, true)) {
            throw new PieceJointeRefusee(self::REFUS);
        }
    }

    /** Une image XObject : pas un flux d'objets deguise (`/First`, `/N`, un autre `/Type`). */
    private function estUneImage(array $dict): bool
    {
        return $this->nom($dict['Subtype'] ?? null) === 'Image'
            && in_array($this->nom($dict['Type'] ?? null), [null, 'XObject'], true)
            && ! array_key_exists('First', $dict) && ! array_key_exists('N', $dict);
    }

    /** @return list<string> les filtres dans l'ordre, doublons compris */
    private function filtres(mixed $valeur): array
    {
        if ($valeur === null) {
            return [];
        }
        $liste = is_array($valeur) && ($valeur['t'] ?? null) === 'tableau' ? $valeur['v'] : [$valeur];

        return array_map(fn ($filtre) => $this->nom($filtre) ?? $this->refuser(), $liste);
    }

    /** @return array<string, int> */
    private function parametresDeDecodage(mixed $valeur): array
    {
        if (is_array($valeur) && ($valeur['t'] ?? null) === 'tableau' && count($valeur['v']) === 1) {
            $valeur = $valeur['v'][0];
        }
        if ($valeur === null || $valeur === 'null') {
            return [];
        }
        if (! is_array($valeur) || ($valeur['t'] ?? null) !== 'dict') {
            $this->refuser();
        }

        $parametres = [];
        foreach (['Predictor', 'Colors', 'BitsPerComponent', 'Columns'] as $cle) {
            if (array_key_exists($cle, $valeur['v'])) {
                $parametres[$cle] = is_int($valeur['v'][$cle]) ? $valeur['v'][$cle] : $this->refuser();
            }
        }

        return $parametres;
    }

    /** La longueur des donnees, directe ou lue sur son objet s'il n'est defini qu'une fois. */
    private function longueur(array $dict, string $contenu): int
    {
        $valeur = $dict['Length'] ?? null;
        if (is_int($valeur)) {
            return $valeur;
        }
        if (! is_array($valeur) || ($valeur['t'] ?? null) !== 'ref') {
            $this->refuser();
        }

        [$numero, $generation] = $valeur['v'];
        preg_match_all('/(?<![0-9])'.$numero.'\s+'.$generation.'\s+obj\s*(\d+)\s*endobj/', $contenu, $m);
        $valeurs = array_unique($m[1]);

        return count($valeurs) === 1 ? (int) reset($valeurs) : $this->refuser();
    }

    /** Inflate le flux jusqu'a sa fin reelle, dans le budget. */
    private function inflater(string $donnees, int $budget): string
    {
        foreach ([ZLIB_ENCODING_DEFLATE, ZLIB_ENCODING_RAW] as $encodage) {
            $contexte = inflate_init($encodage);
            $sortie = '';
            foreach (str_split($donnees, 8192) as $morceau) {
                $bloc = @inflate_add($contexte, $morceau, ZLIB_SYNC_FLUSH);
                if ($bloc === false) {
                    break;
                }
                $sortie .= $bloc;
                if (strlen($sortie) > $budget) {
                    throw new PieceJointeRefusee('Ce PDF est trop complexe pour être vérifié. Envoyez une capture d\'écran à la place.');
                }
                if (inflate_get_status($contexte) === ZLIB_STREAM_END) {
                    return $sortie;
                }
            }
        }

        $this->refuser();
    }

    /**
     * Un predicteur PNG (/Predictor 10 a 15) code chaque octet par difference :
     * un nom n'y apparait plus en clair. On le defait ; tout autre predicteur rend
     * le flux illisible.
     *
     * @param  array<string, int>  $parametres
     */
    private function sansPredicteur(string $texte, array $parametres): string
    {
        $predicteur = $parametres['Predictor'] ?? 1;
        if ($predicteur <= 1) {
            return $texte;
        }
        if ($predicteur < 10 || $predicteur > 15) {
            $this->refuser();
        }

        $bits = ($parametres['Colors'] ?? 1) * ($parametres['BitsPerComponent'] ?? 8);
        $parPixel = max(1, intdiv($bits + 7, 8));
        $largeur = intdiv(($parametres['Columns'] ?? 1) * $bits + 7, 8);
        if ($largeur < 1 || strlen($texte) % ($largeur + 1) !== 0) {
            $this->refuser();
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
                    default => $this->refuser(),
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

    private function nom(mixed $valeur): ?string
    {
        return is_array($valeur) && ($valeur['t'] ?? null) === 'nom' ? $valeur['v'] : null;
    }

    private function refuser(): never
    {
        throw new PieceJointeRefusee(self::ILLISIBLE);
    }
}
