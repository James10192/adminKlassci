<?php

namespace App\Domain\Care\Tickets\Services;

use App\Domain\Care\Tickets\Exceptions\PieceJointeRefusee;

/**
 * Lecture sequentielle de la structure d'un PDF : jetons, dictionnaires, tableaux,
 * references, et les flux, dont les donnees sont sautees par leur `/Length` comme
 * le fait un lecteur. Ce n'est pas un analyseur complet ; il refuse des qu'il ne
 * comprend plus, et c'est voulu.
 *
 * Un lecteur trouve les objets par la table xref, nous en parcourant le fichier :
 * les deux ne voient la meme chose que si rien ne se cache dans une chaine ou un
 * commentaire. D'ou le refus d'un mot-cle `stream` en fin de ligne a l'interieur
 * de l'un ou de l'autre : c'est la seule facon d'y loger un flux que le lecteur
 * verrait et pas nous.
 */
class AnalyseurPdf
{
    private const ILLISIBLE = 'Ce PDF ne peut pas être vérifié. Envoyez une capture d\'écran à la place.';

    private const PROFONDEUR_MAX = 64;

    private int $pos = 0;

    private int $debutJeton = 0;

    private readonly int $taille;

    /** @var callable(string): void */
    private $surNom;

    /** @var (callable(array): void)|null */
    private $surDict = null;

    public function __construct(private readonly string $pdf)
    {
        $this->taille = strlen($pdf);
    }

    /**
     * @param  callable(string): void  $surNom  chaque nom rencontre, echappements decodes
     * @param  callable(array, int, ?int, ?int): int  $surFlux  dictionnaire du flux, debut de ses
     *                                                           donnees, numero et position de
     *                                                           l'objet ; rend la fin des donnees
     * @param  (callable(array): void)|null  $surDict  chaque dictionnaire lu, valeurs comprises
     * @return array{objets: array<int, int>, sections: array<int, array{entrees: list<array{int, string, int}>,
     *                trailer: array}>, startxref: list<int>, types: array<int, list<string>>}
     *         les objets lus (position => numero), chaque table xref classique (position => ses
     *         entrees [numero, 'position'|'libre', valeur] et son trailer), les valeurs de
     *         startxref, et le type de la valeur de chaque objet lu
     */
    public function parcourir(callable $surNom, callable $surFlux, ?callable $surDict = null): array
    {
        $this->surNom = $surNom;
        $this->surDict = $surDict;
        $dernier = null;
        $recents = [];
        $objet = null;
        $positionObjet = null;
        $objets = [];
        $sections = [];
        $tableOuverte = null;
        $startxref = [];
        $types = [];
        $valeurDObjet = false;
        while (($jeton = $this->jeton()) !== null) {
            $debut = $this->debutJeton;
            if ($jeton === 'trailer') {
                // Un trailer clot la table qui le precede ; orphelin, il ne decrit rien.
                $trailer = $this->valeur($this->jetonAttendu(), 0);
                if ($tableOuverte === null || ! is_array($trailer) || $trailer['t'] !== 'dict') {
                    $this->refuser();
                }
                $sections[$tableOuverte]['trailer'] = $trailer['v'];
                $tableOuverte = null;
                [$dernier, $recents] = [null, []];

                continue;
            }
            if ($jeton === 'startxref') {
                $position = $this->jetonAttendu();
                $startxref[] = is_string($position) && ctype_digit($position) ? (int) $position : $this->refuser();
                [$dernier, $recents] = [null, []];

                continue;
            }
            if ($jeton === 'stream') {
                if (! is_array($dernier) || ($dernier['t'] ?? null) !== 'dict') {
                    $this->refuser();
                }
                $this->pos = $surFlux($dernier['v'], $this->debutDesDonnees(), $objet, $positionObjet);
                $this->attendre('endstream');
                [$dernier, $recents] = [null, []];

                continue;
            }
            if ($jeton === 'xref') {
                if ($tableOuverte !== null) {
                    $this->refuser();
                }
                $sections[$debut] = ['entrees' => $this->tableXref(), 'trailer' => null];
                $tableOuverte = $debut;
                [$dernier, $recents] = [null, []];

                continue;
            }
            $valeur = $this->valeur($jeton, 0);
            if ($valeurDObjet) {
                $types[$objet][] = is_array($valeur) ? $valeur['t'] : 'autre';
                $valeurDObjet = false;
            }
            if ($valeur === 'obj' && count($recents) === 2 && is_int($recents[0][0]) && is_int($recents[1][0])) {
                $objets[$recents[0][1]] = $objet = $recents[0][0];
                $positionObjet = $recents[0][1];
                $valeurDObjet = true;
            }
            $recents = array_slice([...$recents, [$valeur, $debut]], -2);
            $dernier = $valeur;
        }

        if ($tableOuverte !== null) {
            $this->refuser();
        }

        return ['objets' => $objets, 'sections' => $sections, 'startxref' => $startxref, 'types' => $types];
    }

    /**
     * @return list<array{int, string, int}> les entrees d'une table xref : [numero, 'position', position]
     *         pour une entree en service, [numero, 'libre', 0] pour une entree liberee. Les libres
     *         comptent : dans une mise a jour, elles masquent l'objet que declarait une table plus ancienne.
     */
    private function tableXref(): array
    {
        $entrees = [];
        while (preg_match('/\G\s*(\d+)\s+(\d+)[ \t]*[\r\n]/', $this->pdf, $section, 0, $this->pos)) {
            $this->pos += strlen($section[0]);
            for ($i = 0; $i < (int) $section[2]; $i++) {
                if (! preg_match('/\G\s*(\d+)\s+(\d+)\s+([fn])/', $this->pdf, $entree, 0, $this->pos)) {
                    $this->refuser();
                }
                $this->pos += strlen($entree[0]);
                $entrees[] = $entree[3] === 'n'
                    ? [(int) $section[1] + $i, 'position', (int) $entree[1]]
                    : [(int) $section[1] + $i, 'libre', 0];
            }
        }

        return $entrees;
    }

    /** Une valeur : dictionnaire, tableau, reference, ou le jeton lui-meme. */
    private function valeur(string|array $jeton, int $profondeur): mixed
    {
        if ($profondeur > self::PROFONDEUR_MAX) {
            $this->refuser();
        }
        if ($jeton === '<<') {
            $dict = [];
            while (($cle = $this->jetonAttendu()) !== '>>') {
                if (! is_array($cle) || $cle['t'] !== 'nom') {
                    $this->refuser();
                }
                $dict[$cle['v']] = $this->valeur($this->jetonAttendu(), $profondeur + 1);
            }
            if ($this->surDict !== null) {
                ($this->surDict)($dict);
            }

            return ['t' => 'dict', 'v' => $dict];
        }
        if ($jeton === '[') {
            $liste = [];
            while (($element = $this->jetonAttendu()) !== ']') {
                $liste[] = $this->valeur($element, $profondeur + 1);
            }

            return ['t' => 'tableau', 'v' => $liste];
        }
        if ($jeton === '>>' || $jeton === ']') {
            $this->refuser();
        }
        if (is_string($jeton) && ctype_digit($jeton)) {
            return $this->referenceOuNombre($jeton);
        }

        return $jeton;
    }

    /** `12 0 R` est une reference ; sinon le nombre seul, sans consommer la suite. */
    private function referenceOuNombre(string $nombre): mixed
    {
        $retour = $this->pos;
        $generation = $this->jeton();
        if (is_string($generation) && ctype_digit($generation) && $this->jeton() === 'R') {
            return ['t' => 'ref', 'v' => [(int) $nombre, (int) $generation]];
        }
        $this->pos = $retour;

        return (int) $nombre;
    }

    /**
     * Le jeton suivant, ou null en fin de fichier. Les delimiteurs sont rendus tels
     * quels, les noms et les chaines sous forme de tableau, le reste en texte.
     */
    private function jeton(): string|array|null
    {
        while (true) {
            $this->pos += strspn($this->pdf, "\0\t\n\f\r ", $this->pos);
            if ($this->pos >= $this->taille) {
                return null;
            }
            if ($this->pdf[$this->pos] !== '%') {
                break;
            }
            $fin = $this->pos + strcspn($this->pdf, "\r\n", $this->pos);
            if (preg_match('/stream\s*$/', substr($this->pdf, $this->pos, $fin - $this->pos))) {
                $this->refuser();
            }
            $this->pos = $fin;
        }

        $this->debutJeton = $this->pos;
        $c = $this->pdf[$this->pos];
        if ($c === '<' || $c === '>') {
            if (($this->pdf[$this->pos + 1] ?? '') === $c) {
                $this->pos += 2;

                return $c.$c;
            }
            if ($c === '>') {
                $this->refuser();
            }

            return $this->chaineHexa();
        }
        if ($c === '[' || $c === ']' || $c === '{' || $c === '}') {
            $this->pos++;

            return $c;
        }
        if ($c === '(') {
            return $this->chaineLitterale();
        }
        if ($c === ')') {
            $this->refuser();
        }

        $debut = $this->pos + ($c === '/' ? 1 : 0);
        $longueur = strcspn($this->pdf, "\0\t\n\f\r ()<>[]{}/%", $debut);
        $this->pos = $debut + $longueur;
        $texte = substr($this->pdf, $debut, $longueur);
        if ($c !== '/') {
            return $texte;
        }

        $nom = (string) preg_replace_callback('/#([0-9A-Fa-f]{2})/', fn ($m) => chr(hexdec($m[1])), $texte);
        ($this->surNom)($nom);

        return ['t' => 'nom', 'v' => $nom];
    }

    private function jetonAttendu(): string|array
    {
        return $this->jeton() ?? $this->refuser();
    }

    private function chaineLitterale(): array
    {
        $debut = ++$this->pos;
        $profondeur = 1;
        while ($profondeur > 0) {
            $this->pos += strcspn($this->pdf, '()\\', $this->pos);
            if ($this->pos >= $this->taille) {
                $this->refuser();
            }
            $c = $this->pdf[$this->pos];
            $this->pos += $c === '\\' ? 2 : 1;
            $profondeur += match ($c) { '(' => 1, ')' => -1, default => 0 };
        }
        if (preg_match('/stream[\r\n]/', substr($this->pdf, $debut, $this->pos - $debut))) {
            $this->refuser();
        }

        return ['t' => 'chaine'];
    }

    private function chaineHexa(): array
    {
        $fin = strpos($this->pdf, '>', $this->pos);
        if ($fin === false || strspn($this->pdf, "0123456789abcdefABCDEF\0\t\n\f\r ", $this->pos + 1) !== $fin - $this->pos - 1) {
            $this->refuser();
        }
        $this->pos = $fin + 1;

        return ['t' => 'chaine'];
    }

    /** Les donnees commencent apres la fin de ligne qui suit `stream`. */
    private function debutDesDonnees(): int
    {
        if (substr($this->pdf, $this->pos, 2) === "\r\n") {
            return $this->pos + 2;
        }
        if (in_array($this->pdf[$this->pos] ?? '', ["\n", "\r"], true)) {
            return $this->pos + 1;
        }

        return $this->refuser();
    }

    private function attendre(string $motCle): void
    {
        $this->pos += strspn($this->pdf, "\0\t\n\f\r ", $this->pos);
        if (substr($this->pdf, $this->pos, strlen($motCle)) !== $motCle) {
            $this->refuser();
        }
        $this->pos += strlen($motCle);
    }

    private function refuser(): never
    {
        throw new PieceJointeRefusee(self::ILLISIBLE);
    }
}
