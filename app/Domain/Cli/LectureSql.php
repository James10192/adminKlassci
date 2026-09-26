<?php

namespace App\Domain\Cli;

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Une requête de lecture sur la base maître, par un utilisateur MySQL dédié.
 *
 * Cette base porte les identifiants de connexion de toutes les écoles. Aucun
 * filtre sur le texte d'une requête ne les protège : un alias, un UNION ou la
 * liste de colonnes d'un WITH renomment les colonnes et déjouent tout masquage
 * par nom. La frontière est donc côté base : une connexion dont l'utilisateur
 * n'a que SELECT, sans les colonnes sensibles (klassci.cli_sql_connexion).
 * Sans elle, la lecture reste fermée.
 *
 * Le filtre qui suit ne fait que refuser tôt, avec un message clair, ce que
 * ces droits refuseraient de toute façon ; la transaction en lecture seule
 * double le refus d'écrire.
 */
final class LectureSql
{
    public const LIGNES_MAX = 200;

    private const DEBUTS_AUTORISES = '/^\s*(select|with|describe|desc|explain|show\s+(full\s+)?(tables|columns|index))\b/i';

    private const INTERDITS = '/\b(into\s+(out|dump)file|load_file\s*\(|for\s+update|lock\s+in\s+share\s+mode|sleep\s*\(|benchmark\s*\(|get_lock\s*\()/i';

    private const SENSIBLES = 'password|passwd|secret|token|credential|remember|api_key|private';

    /**
     * @return array{colonnes: list<string>, lignes: list<array<string, mixed>>, tronque: bool}
     *
     * @throws LectureSqlFermee tant qu'aucune connexion dédiée n'est configurée
     */
    public function executer(string $requete): array
    {
        $connexion = $this->connexion();
        $requete = $this->valider($requete);
        $pilote = $connexion->getDriverName();

        try {
            if ($pilote === 'mysql') {
                // MariaDB (fréquente sur cPanel) n'a pas MAX_EXECUTION_TIME.
                $mariadb = str_contains((string) $connexion->getPdo()->getAttribute(\PDO::ATTR_SERVER_VERSION), 'MariaDB');
                $connexion->statement($mariadb ? 'SET SESSION max_statement_time = 5' : 'SET SESSION MAX_EXECUTION_TIME = 5000');
                $connexion->unprepared('START TRANSACTION READ ONLY');
            } elseif ($pilote === 'sqlite') {
                $connexion->statement('PRAGMA query_only = ON');
            }

            $lignes = $connexion->select($requete);
        } finally {
            if ($pilote === 'mysql') {
                $connexion->unprepared('ROLLBACK');
            } elseif ($pilote === 'sqlite') {
                $connexion->statement('PRAGMA query_only = OFF');
            }
        }

        $tronque = count($lignes) > self::LIGNES_MAX;
        $lignes = array_map(fn ($ligne) => $this->masquer((array) $ligne), array_slice($lignes, 0, self::LIGNES_MAX));

        return [
            'colonnes' => array_keys($lignes[0] ?? []),
            'lignes' => $lignes,
            'tronque' => $tronque,
        ];
    }

    public function valider(string $requete): string
    {
        $requete = rtrim(trim($requete), "; \t\n\r");

        if ($requete === '' || mb_strlen($requete) > 4000) {
            throw new InvalidArgumentException('Requête vide ou trop longue (4 000 caractères au plus).');
        }

        if (str_contains($requete, ';')) {
            throw new InvalidArgumentException('Une seule instruction à la fois.');
        }

        if (preg_match('/\/\*|--|#/', $requete)) {
            throw new InvalidArgumentException('Les commentaires ne sont pas admis (ni /* */, ni --, ni #).');
        }

        if (! preg_match(self::DEBUTS_AUTORISES, $requete)) {
            throw new InvalidArgumentException('Seules les lectures sont admises : SELECT, WITH, DESCRIBE, EXPLAIN, SHOW TABLES/COLUMNS/INDEX.');
        }

        $texte = str_replace('`', '', $requete);

        if (preg_match(self::INTERDITS, $texte, $trouve)) {
            throw new InvalidArgumentException("Construction refusée : « {$trouve[0]} ».");
        }

        if (preg_match('/\w*(' . self::SENSIBLES . ')\w*/i', $texte, $trouve)) {
            throw new InvalidArgumentException("Colonne ou table sensible refusée : « {$trouve[0]} ».");
        }

        return $requete;
    }

    /**
     * Jamais la connexion principale, même par accident : `false` ou `"0"`
     * dans le .env feraient retomber DB::connection() sur elle, et un
     * utilisateur « lecture » recopié depuis la principale lui donnerait ses
     * droits complets.
     */
    private function connexion(): Connection
    {
        $nom = config('klassci.cli_sql_connexion');

        if (! is_string($nom) || trim($nom) === '' || trim($nom) === '0') {
            throw new LectureSqlFermee();
        }

        $nom = trim($nom);
        $principale = (string) config('database.default');
        $reglage = config("database.connections.{$nom}");

        if ($nom === $principale || ! is_array($reglage)) {
            throw new LectureSqlFermee("« {$nom} » n'est pas une connexion dédiée à la lecture.");
        }

        if (($reglage['driver'] ?? null) === 'mysql') {
            $utilisateur = (string) ($reglage['username'] ?? '');
            if ($utilisateur === '' || $utilisateur === (string) config("database.connections.{$principale}.username")) {
                throw new LectureSqlFermee("L'utilisateur MySQL de « {$nom} » doit être un utilisateur à part, en lecture seule.");
            }
        }

        try {
            $connexion = DB::connection($nom);
            $connexion->getPdo();
        } catch (\PDOException $e) {
            throw new LectureSqlFermee("La connexion « {$nom} » ne s'ouvre pas : vérifiez DB_LECTURE_USERNAME / DB_LECTURE_PASSWORD.");
        }

        return $connexion;
    }

    /**
     * Pour les bases de test et un utilisateur dédié mal réglé : ce qui passe
     * sous un nom sensible sort masqué. Ce n'est pas une protection, un
     * renommage le déjoue ; les droits de l'utilisateur le sont.
     *
     * @param array<string, mixed> $ligne
     */
    private function masquer(array $ligne): array
    {
        foreach ($ligne as $colonne => $valeur) {
            if ($valeur !== null && preg_match('/(' . self::SENSIBLES . ')/i', (string) $colonne)) {
                $ligne[$colonne] = '***';
            }
        }

        return $ligne;
    }
}
