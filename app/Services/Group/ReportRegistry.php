<?php

namespace App\Services\Group;

use App\Contracts\Group\GroupPayrollProviderInterface;
use App\Domain\Exports\ExportableReport;
use App\Domain\Exports\Reports\ConsolidationFinanciereReport;
use App\Domain\Exports\Reports\EtatEtablissementsReport;
use App\Domain\Exports\Reports\MasseSalarialeReport;
use App\Domain\Exports\Reports\SanteAbonnementsReport;
use App\Models\Group;
use App\Services\TenantAggregationService;
use App\Support\Period\PeriodFactory;

/**
 * Les rapports que le portail sait produire, désignés par une clé stable.
 *
 * La clé est ce qu'on stocke dans une programmation. Elle doit survivre à un
 * renommage de classe : c'est pourquoi la table ne porte pas un nom de classe
 * PHP, qui deviendrait une programmation morte au premier refactor.
 */
class ReportRegistry
{
    public const ETAT_ETABLISSEMENTS = 'etat_etablissements';
    public const CONSOLIDATION_FINANCIERE = 'consolidation_financiere';
    public const MASSE_SALARIALE = 'masse_salariale';
    public const SANTE_ABONNEMENTS = 'sante_abonnements';

    /** @return array<string, string> [clé => libellé] */
    public function options(): array
    {
        return [
            self::ETAT_ETABLISSEMENTS => 'État des établissements',
            self::CONSOLIDATION_FINANCIERE => 'Consolidation financière',
            self::MASSE_SALARIALE => 'Masse salariale enseignante',
            self::SANTE_ABONNEMENTS => 'Santé et abonnements',
        ];
    }

    public function connait(string $cle): bool
    {
        return array_key_exists($cle, $this->options());
    }

    public function libelle(string $cle): string
    {
        return $this->options()[$cle] ?? $cle;
    }

    /**
     * Construit le rapport, données consolidées comprises.
     *
     * @throws \InvalidArgumentException si la clé n'existe plus — le cas se
     *         produit quand un rapport est retiré alors qu'une programmation
     *         le vise encore ; l'appelant doit le signaler, pas l'ignorer.
     */
    public function construire(string $cle, Group $group): ExportableReport
    {
        $periode = PeriodFactory::default()->label();
        $nom = (string) $group->name;

        return match ($cle) {
            self::ETAT_ETABLISSEMENTS => new EtatEtablissementsReport(
                app(TenantAggregationService::class)->getGroupKpis($group),
                $nom,
                $periode,
            ),
            self::CONSOLIDATION_FINANCIERE => new ConsolidationFinanciereReport(
                app(TenantAggregationService::class)->getGroupFinancials($group),
                $nom,
                $periode,
            ),
            self::MASSE_SALARIALE => new MasseSalarialeReport(
                app(GroupPayrollProviderInterface::class)->computeGroupPayroll($group),
                $nom,
                $periode,
            ),
            self::SANTE_ABONNEMENTS => new SanteAbonnementsReport(
                $group->activeTenants()->orderBy('name')->get(),
                app(TenantAggregationService::class)->getGroupHealthMetrics($group),
                $nom,
                $periode,
            ),
            default => throw new \InvalidArgumentException("Rapport inconnu : {$cle}"),
        };
    }
}
