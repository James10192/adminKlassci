<?php

namespace App\Support;

use Carbon\CarbonInterface;

/**
 * Met en mots et en couleur le nombre de jours restant sur un abonnement.
 *
 * Existe parce que la même échéance était rendue à trois endroits — la liste
 * des établissements, le widget d'alertes du tableau de bord et l'en-tête de
 * la fiche établissement — chacun avec sa propre arithmétique. Or Carbon 3
 * renvoie un flottant depuis diffInDays() : le tableau de bord affichait
 * « Dans 26.948242395116j ».
 *
 * Le calcul lui-même reste dans Tenant::daysRemaining(), qui compare de
 * minuit à minuit ; cette classe ne fait que l'habiller.
 */
final class SubscriptionCountdown
{
    /** En deçà de ce seuil, l'échéance passe en avertissement. */
    public const SOON_DAYS = 30;

    /** Retourne 'gray' | 'danger' | 'warning' | 'success'. */
    public static function tone(?int $days): string
    {
        return match (true) {
            $days === null => 'gray',
            $days < 0 => 'danger',
            $days <= self::SOON_DAYS => 'warning',
            default => 'success',
        };
    }

    /**
     * Libellé affiché dans un badge.
     *
     * Au-delà du seuil, la date exacte informe mieux qu'un décompte : savoir
     * qu'un abonnement court jusqu'au 18/03/2027 est plus utile que « dans
     * 197 j ». En deçà, c'est l'inverse.
     */
    public static function label(?int $days, mixed $endDate = null, string $none = 'N/A'): string
    {
        return match (true) {
            $days === null => $none,
            $days < 0 => 'Expiré',
            $days === 0 => "Aujourd'hui",
            $days === 1 => 'Demain',
            $days <= self::SOON_DAYS => "Dans {$days} j",
            $endDate instanceof CarbonInterface => $endDate->format('d/m/Y'),
            default => "Dans {$days} j",
        };
    }

    /**
     * Fragment inséré dans le sous-titre de la fiche établissement — ne dit
     * rien tant que l'échéance est lointaine, pour ne pas alourdir l'en-tête.
     */
    public static function headerNote(?int $days): ?string
    {
        return match (true) {
            $days === null => null,
            $days < 0 => 'Abonnement expiré',
            $days === 0 => "Abonnement expirant aujourd'hui",
            $days === 1 => 'Abonnement expirant demain',
            $days <= self::SOON_DAYS => "Expire dans {$days} j",
            default => null,
        };
    }
}
