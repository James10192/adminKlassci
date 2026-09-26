<?php

namespace App\Domain\Cli;

use App\Models\User;

/**
 * Ce que chaque rôle de l'équipe KLASSCI peut faire depuis le CLI (klassci admin:*).
 *
 * Le jeton porte les capacités de son rôle au moment où il est émis, et la
 * requête revérifie le rôle actuel : un membre rétrogradé ou désactivé perd
 * ses droits sans qu'on ait à chasser ses jetons.
 */
final class CapacitesCli
{
    public const LIRE = 'cli:lire';        // tenants, santé, déploiements, sauvegardes, demandes, journal
    public const OPERER = 'cli:operer';    // relancer la santé, les stats, une sauvegarde, le scan des dossiers
    public const DEPLOYER = 'cli:deployer';
    public const SQL = 'cli:sql';          // SELECT en lecture seule sur la base maître

    public const PAR_ROLE = [
        'super_admin' => [self::LIRE, self::OPERER, self::DEPLOYER, self::SQL],
        'support' => [self::LIRE, self::OPERER, self::DEPLOYER],
        'billing' => [self::LIRE],
    ];

    /** @return array<int, string> */
    public static function duRole(?string $role): array
    {
        return self::PAR_ROLE[$role] ?? [];
    }

    public static function autorise(User $membre, string $capacite): bool
    {
        return $membre->is_active && in_array($capacite, self::duRole($membre->role), true);
    }
}
