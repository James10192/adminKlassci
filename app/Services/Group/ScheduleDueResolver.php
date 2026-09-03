<?php

namespace App\Services\Group;

use Carbon\CarbonImmutable;

/**
 * Dit si une programmation de rapport doit partir maintenant. Pur : ne lit ni
 * la base ni l'horloge, tout arrive en argument.
 *
 * Deux pannes guettent ce genre de code, et elles sont symétriques :
 *
 *  - Le double envoi. La commande tourne toutes les heures ; comparer « on est
 *    lundi 7 h » enverrait le rapport à chaque passage de l'heure si rien ne
 *    marque le coup.
 *  - L'envoi manqué. Comparer l'heure exacte ferait sauter la semaine entière
 *    dès que le serveur est occupé à 7 h 00 pile — et personne ne s'en rend
 *    compte, puisqu'un rapport qui n'arrive pas ne fait pas de bruit.
 *
 * D'où le raisonnement par PÉRIODE plutôt que par instant : le rapport part
 * une fois par semaine (ou par mois) dès qu'on a dépassé le moment prévu et
 * qu'on n'a rien envoyé depuis le début de cette période. Un serveur en panne
 * de 7 h à 11 h rattrape à 11 h ; il n'envoie pas quatre fois.
 */
class ScheduleDueResolver
{
    public const HEBDOMADAIRE = 'weekly';
    public const MENSUEL = 'monthly';

    public function estDue(
        string $frequence,
        ?int $jourSemaine,
        ?int $jourMois,
        int $heure,
        ?CarbonImmutable $dernierEnvoi,
        CarbonImmutable $maintenant,
    ): bool {
        $debutPeriode = $this->debutPeriode($frequence, $maintenant);
        $momentPrevu = $this->momentPrevu($frequence, $jourSemaine, $jourMois, $heure, $maintenant);

        if ($maintenant->lessThan($momentPrevu)) {
            return false;
        }

        // Déjà parti pour cette semaine / ce mois.
        if ($dernierEnvoi !== null && $dernierEnvoi->greaterThanOrEqualTo($debutPeriode)) {
            return false;
        }

        return true;
    }

    /** Début de la semaine ISO ou du mois courant. */
    public function debutPeriode(string $frequence, CarbonImmutable $maintenant): CarbonImmutable
    {
        return $frequence === self::MENSUEL
            ? $maintenant->startOfMonth()
            : $maintenant->startOfWeek(CarbonImmutable::MONDAY);
    }

    /**
     * Moment prévu dans la période courante.
     *
     * Un jour du mois qui n'existe pas — le 31 en février — est ramené au
     * dernier jour du mois, sinon la programmation sauterait tous les mois
     * courts sans jamais le dire.
     */
    public function momentPrevu(
        string $frequence,
        ?int $jourSemaine,
        ?int $jourMois,
        int $heure,
        CarbonImmutable $maintenant,
    ): CarbonImmutable {
        $heure = max(0, min(23, $heure));

        if ($frequence === self::MENSUEL) {
            $jour = max(1, min(31, $jourMois ?? 1));
            $jour = min($jour, $maintenant->daysInMonth);

            return $maintenant->startOfMonth()->addDays($jour - 1)->setTime($heure, 0);
        }

        $jour = max(1, min(7, $jourSemaine ?? 1));

        return $maintenant->startOfWeek(CarbonImmutable::MONDAY)
            ->addDays($jour - 1)
            ->setTime($heure, 0);
    }
}
