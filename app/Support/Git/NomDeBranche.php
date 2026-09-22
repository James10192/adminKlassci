<?php

namespace App\Support\Git;

/**
 * Forme admissible d'un nom de branche Git avant qu'il n'atteigne un processus.
 *
 * Le nom de branche arrive de l'exterieur (webhook, formulaire Filament, option
 * --branch, colonne tenants.git_branch) et finit dans un appel a git execute sur
 * le serveur qui heberge TOUS les tenants. Tant qu'il etait interpole dans une
 * chaine passee a un shell, `presentation;curl …|sh` y executait n'importe quoi.
 *
 * Les appels sont desormais en argv (aucun shell ne les lit). Cette garde reste,
 * parce qu'un nom commencant par `-` serait lu par git comme une OPTION meme en
 * argv — c'est la seule injection que l'argv ne ferme pas.
 *
 * La liste blanche est plus etroite que ce que git accepte (check-ref-format) :
 * c'est voulu, les branches KLASSCI sont des codes de tenant et des feat/…
 */
final class NomDeBranche
{
    public const LONGUEUR_MAX = 100;

    // \A…\z et non ^…$ : en PCRE, `$` accepte un saut de ligne final.
    public const MOTIF = '/\A[A-Za-z0-9._\/-]{1,100}\z/';

    /**
     * Rend null si le nom est admissible, sinon la raison du refus (en francais,
     * affichable tel quel a l'utilisateur).
     */
    public static function motifDeRefus(mixed $nom): ?string
    {
        if (! is_string($nom) || $nom === '') {
            return 'Le nom de branche est vide.';
        }

        if (preg_match(self::MOTIF, $nom) !== 1) {
            return 'Le nom de branche ne peut contenir que des lettres, chiffres, « . », « _ », « / » et « - » (100 caracteres au plus).';
        }

        if (str_starts_with($nom, '-')) {
            return 'Le nom de branche ne peut pas commencer par « - » : git le lirait comme une option.';
        }

        if (str_contains($nom, '..') || str_contains($nom, '//')) {
            return 'Le nom de branche ne peut contenir ni « .. » ni « // ».';
        }

        if (str_starts_with($nom, '.') || str_starts_with($nom, '/')
            || str_ends_with($nom, '/') || str_ends_with($nom, '.')
            || str_ends_with($nom, '.lock') || str_contains($nom, '/.')) {
            return 'Le nom de branche n\'est pas un nom de reference Git valide.';
        }

        return null;
    }

    public static function estValide(mixed $nom): bool
    {
        return self::motifDeRefus($nom) === null;
    }
}
