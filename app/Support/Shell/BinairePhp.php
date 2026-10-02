<?php

namespace App\Support\Shell;

/**
 * L'exécutable PHP en ligne de commande sur l'hébergement cPanel / CloudLinux.
 *
 * Le `php` du PATH y est souvent le binaire LSAPI du serveur web, qui ne sait
 * pas lancer `artisan` : on préfère le CLI d'alt-php, puis celui d'ea-php.
 */
final class BinairePhp
{
    private const CANDIDATS = [
        '/opt/alt/php83/usr/bin/php',
        '/opt/alt/php82/usr/bin/php',
        '/usr/local/bin/php',
        '/opt/cpanel/ea-php84/root/usr/bin/php',
        '/opt/cpanel/ea-php83/root/usr/bin/php',
        '/opt/cpanel/ea-php82/root/usr/bin/php',
    ];

    public static function detecter(): string
    {
        foreach (self::CANDIDATS as $candidat) {
            if (is_file($candidat) && is_executable($candidat)) {
                return $candidat;
            }
        }

        return 'php';
    }
}
