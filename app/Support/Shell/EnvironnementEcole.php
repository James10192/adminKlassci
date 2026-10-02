<?php

namespace App\Support\Shell;

/**
 * L'environnement d'un processus lancé dans le dossier d'une école.
 *
 * Symfony Process transmet par défaut à l'enfant tout l'environnement
 * d'adminKlassci, que Dotenv a rempli avec SON `.env` (DB_DATABASE=klassci_master,
 * APP_KEY…). Côté école, Dotenv ne remplace pas une variable déjà présente : un
 * `artisan migrate` lancé ainsi visait la base d'adminKlassci. Comme la
 * configuration des écoles n'est pas mise en cache, rien ne l'en protégeait.
 *
 * On retire donc chaque variable héritée (valeur `false` = supprimée pour
 * Symfony Process), sauf celles dont un shell, git et Composer ont besoin.
 */
final class EnvironnementEcole
{
    /** Un proxy ou un certificat ajouté au serveur s'ajoute ici, sinon git échouera sans raison visible. */
    private const GARDEES = [
        'PATH', 'HOME', 'USER', 'LOGNAME', 'LANG', 'LC_ALL', 'TMPDIR', 'XDG_CONFIG_HOME',
        'COMPOSER_HOME', 'SSH_AUTH_SOCK', 'GIT_SSH_COMMAND', 'GIT_SSL_CAINFO', 'SSL_CERT_FILE',
        'http_proxy', 'https_proxy', 'no_proxy', 'HTTP_PROXY', 'HTTPS_PROXY', 'NO_PROXY',
    ];

    /** @return array<string, string|false> */
    public static function pour(): array
    {
        $env = [];
        foreach (array_keys(getenv() + $_ENV + $_SERVER) as $cle) {
            if (is_string($cle) && $cle !== '' && ! in_array($cle, self::GARDEES, true)) {
                $env[$cle] = false;
            }
        }

        if (! getenv('HOME')) {
            $env['HOME'] = posix_getpwuid(posix_geteuid())['dir'] ?? '/tmp';
        }

        return $env;
    }
}
