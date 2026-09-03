<?php

namespace App\Contracts\Group;

use App\Models\Group;
use App\Models\Tenant;
use App\Support\Period\PeriodInterface;

/**
 * Masse salariale enseignante, agrégée pour la direction du groupe.
 *
 * Le portail ne montrait jusqu'ici que des recettes. Sans coût en face, un
 * directeur général lit un chiffre d'encaissement et n'a aucun moyen de savoir
 * ce qu'il reste. La paie est la dépense que KLASSCI sait calculer : les taux
 * horaires et les bulletins vivent déjà dans chaque établissement.
 *
 * Base de calcul : la PÉRIODE DE PAIE (colonnes `annee` / `mois`), pas la date
 * de virement. Un bulletin validé mais non versé n'a pas de date de paiement ;
 * l'écarter reviendrait à ne compter que la trésorerie sortie et à sous-estimer
 * le coût du mois. Les recettes, elles, sont fenêtrées sur la date
 * d'encaissement : les deux séries ne sont donc pas sur la même base, et une
 * comparaison recette/coût sur une fenêtre courte doit être lue en sachant cela.
 */
interface GroupPayrollProviderInterface
{
    /**
     * Masse salariale consolidée sur tous les établissements actifs du groupe.
     *
     * Quand $period est null, PeriodFactory::default() s'applique — même
     * convention que les autres fournisseurs du portail.
     *
     * @return array<string,mixed>
     */
    public function computeGroupPayroll(Group $group, ?PeriodInterface $period = null): array;

    /**
     * Masse salariale d'un seul établissement (mêmes conventions).
     *
     * @return array<string,mixed>
     */
    public function computeTenantPayroll(Tenant $tenant, ?PeriodInterface $period = null): array;
}
