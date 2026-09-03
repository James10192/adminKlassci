<?php

namespace App\Services\Group;

/**
 * Classe un bulletin de paie dans l'un des quatre états qui comptent pour la
 * direction. Pure : consomme le couple de colonnes de statut d'une ligne et
 * rend un verdict. L'appelant fait la requête — même séparation que
 * TeacherWorkloadResolver, SubscriptionTierResolver et HealthCheckAlertResolver.
 *
 * Pourquoi une classe plutôt qu'un CASE SQL : la table porte DEUX colonnes de
 * statut, et leur cohabitation est un piège silencieux.
 *
 *  - `statut` est l'ancienne colonne (avril 2025) : « en attente », « payé »,
 *    « annulé ».
 *  - `workflow_status` est le workflow OHADA arrivé en juin 2026 : brouillon →
 *    valide → paye (annule). Sa migration l'a ajoutée avec la valeur par défaut
 *    « brouillon » et n'a rien rétro-rempli.
 *
 * Conséquence : toute paie antérieure à juin 2026 porte `workflow_status =
 * brouillon`, y compris celles qui ont réellement été versées. Filtrer sur le
 * seul workflow ferait disparaître ces bulletins de la masse salariale — sans
 * erreur, sans trou visible, juste un coût sous-estimé. C'est exactement le
 * genre de valeur fausse et silencieuse qu'un tableau de bord de direction ne
 * doit pas produire, d'où la règle de repli sur l'ancienne colonne.
 */
class PayrollStateResolver
{
    /** Versé : l'argent est sorti. */
    public const PAYE = 'paye';

    /** Engagé : validé, dû à l'enseignant, pas encore versé. */
    public const ENGAGE = 'engage';

    /** Brouillon : préparé, pas encore un engagement. */
    public const BROUILLON = 'brouillon';

    /** Annulé : ne compte nulle part. */
    public const ANNULE = 'annule';

    /**
     * @param  string|null  $workflowStatus  Colonne `workflow_status` (juin 2026).
     * @param  string|null  $statutLegacy    Colonne `statut` (avril 2025).
     * @return self::PAYE|self::ENGAGE|self::BROUILLON|self::ANNULE
     */
    public function resolve(?string $workflowStatus, ?string $statutLegacy = null): string
    {
        $workflow = trim((string) $workflowStatus);
        $legacy = trim((string) $statutLegacy);

        // Une annulation prime sur tout le reste : un bulletin legacy marqué
        // « payé » puis annulé dans le nouveau workflow reste annulé.
        if ($workflow === self::ANNULE || $legacy === 'annulé') {
            return self::ANNULE;
        }

        // Le workflow fait foi quand il a bougé ; sinon on lit l'ancienne
        // colonne, seul témoin restant pour les bulletins d'avant juin 2026.
        if ($workflow === self::PAYE || $legacy === 'payé') {
            return self::PAYE;
        }

        if ($workflow === 'valide') {
            return self::ENGAGE;
        }

        return self::BROUILLON;
    }

    /**
     * Un bulletin pèse-t-il sur le résultat de l'établissement ?
     *
     * Versé et engagé comptent tous deux : un bulletin validé est une dette
     * envers l'enseignant, même si le virement n'est pas parti. Un brouillon
     * n'engage encore personne.
     */
    public function peseSurLeResultat(string $etat): bool
    {
        return $etat === self::PAYE || $etat === self::ENGAGE;
    }
}
