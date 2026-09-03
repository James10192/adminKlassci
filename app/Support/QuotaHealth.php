<?php

namespace App\Support;

/**
 * À partir de quand un quota d'abonnement mérite d'être signalé.
 *
 * Ces deux seuils étaient écrits en dur dans `collectQuotaAlerts()`, et la
 * colonne « Inscriptions » du tableau des établissements, elle, ne connaissait
 * qu'un test binaire `isOverLimit() ? danger : success`. Les deux écrans du
 * même portail se contredisaient donc : Rostan Bouaké à 690/700 s'affichait en
 * VERT dans le tableau pendant que le bandeau d'alertes, deux clics plus haut,
 * annonçait « Quota students à 98.6 % ». Le fondateur devait choisir lequel
 * croire.
 *
 * Une seule source, comme pour `RateHealth` : le moteur d'alertes et la
 * couleur de la cellule lisent désormais la même règle, et une école à 98,6 %
 * est orange des deux côtés.
 *
 * Les valeurs sont de la configuration, pas des constantes. Une école qui
 * remplit ses effectifs à 95 % chaque rentrée sans jamais déborder ne veut pas
 * la même alerte qu'un groupe qui pilote au plus juste.
 */
final class QuotaHealth
{
    /** Défaut livré — surchargeable via GROUP_PORTAL_QUOTA_EXCEEDED. */
    public const EXCEEDED = 100.0;

    /** Défaut livré — surchargeable via GROUP_PORTAL_QUOTA_CRITICAL. */
    public const CRITICAL = 90.0;

    /** Au-delà, le quota est dépassé : l'école est bloquée ou va l'être. */
    public static function exceededThreshold(): float
    {
        return (float) config('group_portal.quota_health.exceeded', self::EXCEEDED);
    }

    /** Au-delà (sans dépasser), le quota approche : il faut agir avant la rentrée. */
    public static function criticalThreshold(): float
    {
        return (float) config('group_portal.quota_health.critical', self::CRITICAL);
    }

    /** Retourne 'danger' | 'warning' | 'success'. Même vocabulaire que RateHealth::tone(). */
    public static function tone(float $usagePct): string
    {
        return match (true) {
            $usagePct >= self::exceededThreshold() => 'danger',
            $usagePct >= self::criticalThreshold() => 'warning',
            default => 'success',
        };
    }

    /**
     * Le pourcentage d'occupation d'un quota.
     *
     * Un plafond nul ou absent veut dire « illimité », pas « plein » : on
     * retourne 0, jamais une division par zéro ni un 100 % inventé.
     */
    public static function percentage(?int $used, ?int $limit): float
    {
        if ($limit === null || $limit <= 0) {
            return 0.0;
        }

        return round((($used ?? 0) / $limit) * 100, 1);
    }
}
