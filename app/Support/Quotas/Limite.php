<?php

namespace App\Support\Quotas;

/**
 * Une limite de plan telle qu'on la lit.
 *
 * Les plans illimités stockent 999 999 (convention des plans d'abonnement).
 * Affiché brut, ce nombre faisait lire « 2 / 999 999 » sur une école Élite.
 */
final class Limite
{
    public const ILLIMITE = 999999;

    public static function estIllimitee(?int $limite): bool
    {
        return $limite !== null && $limite >= self::ILLIMITE;
    }

    public static function afficher(?int $limite): string
    {
        if ($limite === null) {
            return '—';
        }

        return self::estIllimitee($limite) ? 'illimité' : number_format($limite, 0, ',', ' ');
    }

    /** « 12 / 50 », ou « 12 » seul quand le plan ne limite pas. */
    public static function usage(?int $actuel, ?int $limite): string
    {
        $actuelLisible = number_format((int) $actuel, 0, ',', ' ');

        return self::estIllimitee($limite) ? $actuelLisible : $actuelLisible . ' / ' . self::afficher($limite);
    }
}
