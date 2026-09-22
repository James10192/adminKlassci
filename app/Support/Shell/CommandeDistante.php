<?php

namespace App\Support\Shell;

/**
 * La ligne de commande qu'un shell distant (ssh) executera.
 *
 * ssh ne transmet qu'une CHAINE : le shell distant la relit, l'argv ne peut
 * donc pas aller jusqu'au bout. Chaque element est cite par escapeshellarg(),
 * ce qui en fait un mot unique et inerte quel que soit son contenu (`;`, `$( )`,
 * apostrophe…). C'est la seule construction de commande shell tolerée ici :
 * en local, l'appelant passe l'argv directement a Process, sans shell.
 */
final class CommandeDistante
{
    /**
     * @param list<string> $argv
     */
    public static function construire(string $repertoire, array $argv): string
    {
        return 'cd ' . escapeshellarg($repertoire)
            . ' && ' . implode(' ', array_map('escapeshellarg', $argv));
    }
}
