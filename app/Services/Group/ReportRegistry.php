<?php

namespace App\Services\Group;

use App\Contracts\Group\GroupPayrollProviderInterface;
use App\Domain\Exports\ExportableReport;
use App\Domain\Exports\Reports\ConsolidationFinanciereReport;
use App\Domain\Exports\Reports\DetailPaiementsReport;
use App\Domain\Exports\Reports\EffectifsScolariteReport;
use App\Domain\Exports\Reports\EtatEtablissementsReport;
use App\Domain\Exports\Reports\MasseSalarialeReport;
use App\Domain\Exports\Reports\SanteAbonnementsReport;
use App\Domain\Exports\Reports\SituationParEtudiantReport;
use App\Models\Group;
use App\Services\Group\Detail\FournisseurDetailPaiements;
use App\Services\Group\Detail\FournisseurEffectifs;
use App\Services\Group\Detail\FournisseurSituationEtudiants;
use App\Services\TenantAggregationService;
use App\Support\Filtres\FiltresRapport;
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

    // Les états de DÉTAIL. Ils descendent sous l'établissement — une ligne par
    // paiement, par étudiant — et ne se produisent donc pas sans cadrage : voir
    // FiltresRapport pour la raison, qui tient au volume et pas au confort.
    public const DETAIL_PAIEMENTS = 'detail_paiements';
    public const SITUATION_ETUDIANTS = 'situation_etudiants';
    public const EFFECTIFS_SCOLARITE = 'effectifs_scolarite';

    /** @return array<string, string> [clé => libellé] */
    public function options(): array
    {
        return [
            self::ETAT_ETABLISSEMENTS => 'État des établissements',
            self::CONSOLIDATION_FINANCIERE => 'Consolidation financière',
            self::MASSE_SALARIALE => 'Masse salariale enseignante',
            self::SANTE_ABONNEMENTS => 'Santé et abonnements',
            self::DETAIL_PAIEMENTS => 'Détail des paiements',
            self::SITUATION_ETUDIANTS => 'Situation par étudiant',
            self::EFFECTIFS_SCOLARITE => 'Effectifs et scolarité',
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
    public function construire(string $cle, Group $group, ?FiltresRapport $filtres = null): ExportableReport
    {
        // La période venait de `PeriodFactory::default()`, en dur. Le sélecteur
        // affiché en tête du portail ne parvenait donc JAMAIS aux documents :
        // on pouvait choisir « mois en cours » à l'écran et exporter l'année.
        // Elle suit désormais le cadrage quand il y en a un.
        $periode = ($filtres?->periode() ?? PeriodFactory::default())->label();
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
            self::DETAIL_PAIEMENTS => $this->detailPaiements($group, $filtres),
            self::SITUATION_ETUDIANTS => $this->situationEtudiants($group, $filtres),
            self::EFFECTIFS_SCOLARITE => $this->effectifs($group, $filtres),
            default => throw new \InvalidArgumentException("Rapport inconnu : {$cle}"),
        };
    }

    private function detailPaiements(Group $group, ?FiltresRapport $filtres): ExportableReport
    {
        $filtres ??= FiltresRapport::paiementsParDefaut();

        return new DetailPaiementsReport(
            app(FournisseurDetailPaiements::class)->pourGroupe($group, $filtres),
            (string) $group->name,
            $filtres,
            $this->nomsRetenus($group, $filtres),
        );
    }

    private function situationEtudiants(Group $group, ?FiltresRapport $filtres): ExportableReport
    {
        $filtres ??= FiltresRapport::etudiantsParDefaut();

        return new SituationParEtudiantReport(
            app(FournisseurSituationEtudiants::class)->pourGroupe($group, $filtres),
            (string) $group->name,
            $filtres,
            $this->nomsRetenus($group, $filtres),
        );
    }

    private function effectifs(Group $group, ?FiltresRapport $filtres): ExportableReport
    {
        $filtres ??= FiltresRapport::etudiantsParDefaut();

        return new EffectifsScolariteReport(
            app(FournisseurEffectifs::class)->pourGroupe($group, $filtres),
            (string) $group->name,
            $filtres,
            $this->nomsRetenus($group, $filtres),
        );
    }

    /**
     * Les écoles nommées dans le bandeau — seulement quand le périmètre est
     * restreint. Lister les quatre écoles d'un groupe qui en compte quatre
     * n'apprend rien et pousse le bandeau sur deux lignes.
     *
     * @return array<int, string>
     */
    private function nomsRetenus(Group $group, FiltresRapport $filtres): array
    {
        if ($filtres->etablissements === []) {
            return [];
        }

        return $group->activeTenants
            ->filter(fn ($t): bool => $filtres->retient($t->code))
            ->pluck('name')
            ->values()
            ->all();
    }
}
