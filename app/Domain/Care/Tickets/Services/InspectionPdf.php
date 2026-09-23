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
        'SubmitForm', 'ImportData', 'GoToE', 'GoToR', 'Rendition'];

    private const REFUS = 'Ce PDF contient des éléments actifs et ne peut pas être joint. Envoyez une capture d\'écran à la place.';

    private const ILLISIBLE = 'Ce PDF ne peut pas être vérifié. Envoyez une capture d\'écran à la place.';

    private const PROTEGE = 'Ce PDF est protégé et ne peut pas être vérifié. Envoyez une capture d\'écran à la place.';

    public function verifier(string $contenu, int $budgetInflation): void
    {
        $fluxXref = [];
        $lus = [];
        $ouvertures = [];
        $structure = (new AnalyseurPdf($contenu))->parcourir(
            fn (string $nom) => $this->juger($nom),
            function (array $dict, int $debut, ?int $objet, ?int $position) use ($contenu, &$budgetInflation, &$fluxXref, &$lus): int {
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
                    $fluxXref[$position ?? $this->refuser()] = $this->lireFluxXref($texte, $dict, $objet);
                }

                return $fin;
            },
            function (array $dict) use (&$ouvertures) {
                $ouvertures[] = $dict['OpenAction'] ?? null;
            },
        );

        $this->verifierXref($contenu, $structure, $structure['sections'] + $fluxXref, $lus);
        foreach ($ouvertures as $ouverture) {
            $this->jugerOuverture($ouverture, $structure['types']);
        }
    }

    /**
     * Un lecteur trouve le document par le dernier `startxref`, la section xref qui s'y
     * trouve, puis celles que designent `/XRefStm` et `/Prev`, de proche en proche.
     * Pour chaque numero d'objet, la premiere section de cette chaine qui en parle
     * l'emporte, et `/Root` se lit dans le premier trailer qui le porte. Si ce chemin
     * n'aboutit pas a un objet que l'analyseur a lu, le lecteur reconstruit la table en
     * balayant le fichier, et y trouve des objets que nous n'avons pas vus — caches,
     * par exemple, dans les donnees d'une image. Une section hors de la chaine ne
     * legitime donc rien : c'est la chaine qui est jugee, pas l'union des tables.
     *
     * @param  array{objets: array<int, int>, startxref: list<int>}  $structure
     * @param  array<int, array{entrees: list<array{int, string, int}>, trailer: ?array}>  $sections
     *         chaque section xref, table ou flux, par position de son debut
     * @param  array<int, true>  $lus  les flux d'objets lus, par numero
     */
    private function verifierXref(string $contenu, array $structure, array $sections, array $lus): void
    {
        $debut = fn (int $position) => $position + strspn($contenu, "\0\t\n\f\r ", $position);
        $sectionA = fn (int $position) => $sections[$debut($position)] ?? $this->refuser();

        // Chaque entree, meme hors chaine, designe l'objet qui porte son numero.
        foreach ($sections as $section) {
            foreach ($section['entrees'] as [$numero, $type, $valeur]) {
                $valide = match ($type) {
                    'position' => ($structure['objets'][$debut($valeur)] ?? null) === $numero,
                    'compresse' => isset($lus[$valeur]),
                    default => true,
                };
                if (! $valide) {
                    $this->refuser();
                }
            }
        }

        if ($structure['startxref'] === []) {
            $this->refuser();
        }
        foreach ($structure['startxref'] as $position) {
            $sectionA($position);
        }

        // La chaine du lecteur : chaque section, son /XRefStm (fichier hybride), puis /Prev.
        $chaine = [];
        $trailers = [];
        $vues = [];
        $prendre = function (int $position) use (&$vues, &$chaine, $debut, $sectionA): array {
            $section = $sectionA($position);
            if (isset($vues[$debut($position)]) || $section['trailer'] === null) {
                $this->refuser();
            }
            $vues[$debut($position)] = true;
            $chaine[] = $section;

            return $section['trailer'];
        };
        $suivante = end($structure['startxref']);
        while ($suivante !== null) {
            $trailer = $prendre($suivante);
            $trailers[] = $trailer;
            if (array_key_exists('XRefStm', $trailer)) {
                $prendre(is_int($trailer['XRefStm']) ? $trailer['XRefStm'] : $this->refuser());
            }
            $suivante = array_key_exists('Prev', $trailer)
                ? (is_int($trailer['Prev']) ? $trailer['Prev'] : $this->refuser())
                : null;
        }

        $resolu = [];
        foreach ($chaine as $section) {
            foreach ($section['entrees'] as [$numero, $type]) {
                $resolu[$numero] ??= $type;
            }
        }

        // Le point d'entree du document doit se resoudre, par la chaine, sur un objet lu.
        $racine = null;
        foreach ($trailers as $trailer) {
            if (array_key_exists('Root', $trailer)) {
                $racine = $trailer['Root'];
                break;
            }
        }
        if (($racine['t'] ?? null) !== 'ref' || ! in_array($resolu[$racine['v'][0]] ?? 'libre', ['position', 'compresse'], true)) {
            $this->refuser();
        }
    }

    /**
     * Les entrees d'un flux xref (`/W`, `/Index`) : type 0, entree liberee ; type 1,
     * position d'un objet ; type 2, numero du flux d'objets qui le contient. Le
     * dictionnaire du flux lui sert de trailer.
     *
     * @return array{entrees: list<array{int, string, int}>, trailer: array}
     */
    private function lireFluxXref(string $texte, array $dict, ?int $objet): array
    {
        $largeurs = $this->entiers($dict['W'] ?? null);
        $taille = $dict['Size'] ?? null;
        if (count($largeurs) !== 3 || ! is_int($taille) || $objet === null) {
            $this->refuser();
        }
        $index = array_key_exists('Index', $dict) ? $this->entiers($dict['Index']) : [0, $taille];
        $ligne = array_sum($largeurs);
        if ($ligne < 1 || count($index) % 2 !== 0) {
            $this->refuser();
        }

        $entrees = [];
        $pos = 0;
        foreach (array_chunk($index, 2) as [$premier, $nombre]) {
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
                $entrees[] = match ($champs[0] ?? 1) {
                    0 => [$premier + $i, 'libre', 0],
                    1 => [$premier + $i, 'position', (int) $champs[1]],
                    2 => [$premier + $i, 'compresse', (int) $champs[1]],
                    // Un type inconnu se lit comme une reference nulle (ISO 32000-1, 7.5.8.3).
                    default => [$premier + $i, 'libre', 0],
                };
            }
        }

        return ['entrees' => $entrees, 'trailer' => $dict];
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

    /**
     * Une page d'ouverture est inoffensive : un tableau, ou la reference d'un objet
     * defini une seule fois, en clair, comme tableau (Ghostscript l'ecrit ainsi).
     * Une action, ou ce qu'on ne voit pas, non.
     *
     * @param  array<int, list<string>>  $types
     */
    private function jugerOuverture(mixed $valeur, array $types): void
    {
        $type = $valeur['t'] ?? null;
        if ($valeur === null || $type === 'tableau') {
            return;
        }
        if ($type === 'ref' && ($types[$valeur['v'][0]] ?? null) === ['tableau']) {
            return;
        }

        throw new PieceJointeRefusee(self::REFUS);
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
