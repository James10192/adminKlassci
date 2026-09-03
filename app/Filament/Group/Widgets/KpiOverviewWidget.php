<?php

namespace App\Filament\Group\Widgets;

use App\Filament\Group\Concerns\PeriodAwareConcern;
use App\Services\TenantAggregationService;
use App\Support\EtatMesure;
use App\Support\RateHealth;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class KpiOverviewWidget extends StatsOverviewWidget
{
    use PeriodAwareConcern;

    protected static ?int $sort = 2;

    protected static ?string $pollingInterval = '300s';

    protected function getColumns(): int
    {
        return 3;
    }

    protected function getStats(): array
    {
        $group = auth('group')->user()->group;
        $service = app(TenantAggregationService::class);
        // All 3 service calls MUST receive the same period — otherwise MoM/YoY
        // deltas would be computed against a different window than the snapshot.
        $period = $this->currentPeriod();

        $kpis = $service->getGroupKpis($group, $period);
        $trends = $service->getGroupTrends($group, $period);
        $aging = $service->getGroupOutstandingAging($group, $period);

        $perimetre = $kpis['perimetre'] ?? [];
        $etatFinances = $perimetre['finances']['etat'] ?? EtatMesure::MESURE;
        $etatEffectifs = $perimetre['effectifs']['etat'] ?? EtatMesure::MESURE;
        $etatPersonnel = $perimetre['personnel']['etat'] ?? EtatMesure::MESURE;
        $etatAssiduite = $perimetre['assiduite']['etat'] ?? EtatMesure::MESURE;
        $etatAging = $aging['perimetre']['etat'] ?? EtatMesure::MESURE;
        $etatTrends = $trends['perimetre']['etat'] ?? EtatMesure::MESURE;

        // La mention de perimetre, calculee une fois par famille. Elle est
        // nulle quand le total est complet — c'est la seule situation ou le
        // portail se tait.
        $mention = function (array $p): ?string {
            return EtatMesure::mentionReleves($p['releves'] ?? 0)
                ?? EtatMesure::mentionPerimetre($p['repondu'] ?? 0, $p['total'] ?? 0);
        };
        $mEffectifs = $mention($perimetre['effectifs'] ?? []);
        $mFinances = $mention($perimetre['finances'] ?? []);
        // L'assiduite etait la SEULE famille sans sa mention : la tuile disait
        // « moyenne ponderee groupe » sans reserve, alors qu'une ecole qui ne
        // fait pas l'appel en est desormais (a juste titre) exclue. Un groupe
        // ou une ecole sur quatre emarge presentait donc le taux de cette ecole
        // comme celui du groupe.
        $mAssiduite = $mention($perimetre['assiduite'] ?? []);
        $mAging = $mention($aging['perimetre'] ?? []);
        $mTrends = $mention($trends['perimetre'] ?? []);

        // Delta MoM revenus
        //
        // Le delta reste affichable sur un perimetre ampute : `current` et
        // `previous` sortent du meme ensemble de repondants. C'est sa valeur
        // ABSOLUE qui est incomplete, et c'est elle qui porte la mention.
        $revenueDelta = $trends['revenue_mom']['delta_pct'] ?? 0;
        $revenueDeltaStr = ($revenueDelta > 0 ? '+' : '') . $revenueDelta . '%';
        $revenueIcon = match (true) {
            $revenueDelta > 5 => 'heroicon-o-arrow-trending-up',
            $revenueDelta < -5 => 'heroicon-o-arrow-trending-down',
            default => 'heroicon-o-minus-small',
        };
        $revenueColor = match (true) {
            ! EtatMesure::estMesure($etatTrends) => 'gray',
            $revenueDelta > 0 => 'success',
            $revenueDelta < -15 => 'danger',
            $revenueDelta < 0 => 'warning',
            default => 'gray',
        };

        // Delta YoY inscriptions
        $inscDelta = $trends['inscriptions_yoy']['delta_pct'] ?? 0;
        $inscDeltaStr = ($inscDelta > 0 ? '+' : '') . $inscDelta . '%';
        $inscIcon = match (true) {
            $inscDelta > 5 => 'heroicon-o-arrow-trending-up',
            $inscDelta < -5 => 'heroicon-o-arrow-trending-down',
            default => 'heroicon-o-minus-small',
        };

        // Couleur recouvrement — via RateHealth, dont les seuils sont
        // configurables. Le `>= 70` retape ici avait deja diverge.
        $collectionRate = $kpis['collection_rate'] ?? 0;
        $collectionColor = EtatMesure::estMesure($etatFinances)
            ? RateHealth::tone((float) $collectionRate)
            : 'gray';

        // Impayés > 30j cross-groupe (cumul 31-60 + 61-90 + 90+)
        $impayes30j = ($aging['31-60']['amount'] ?? 0) + ($aging['61-90']['amount'] ?? 0) + ($aging['90+']['amount'] ?? 0);
        $impayes30jCount = ($aging['31-60']['count'] ?? 0) + ($aging['61-90']['count'] ?? 0) + ($aging['90+']['count'] ?? 0);
        $agingMesure = EtatMesure::estMesure($etatAging);

        // Attendance
        //
        // Le barème est celui de l'ASSIDUITÉ, pas celui du recouvrement : les
        // deux ne jugent pas la même chose et un refactor les avait confondus,
        // faisant passer 72 % de « à surveiller » à « sain » en silence.
        $attendance = $kpis['avg_attendance_rate'] ?? 0;
        $attendanceMesure = EtatMesure::aUneValeur($etatAssiduite);
        $attendanceColor = $attendanceMesure
            ? RateHealth::tone((float) $attendance, RateHealth::BAREME_ASSIDUITE)
            : 'gray';

        $tiret = EtatMesure::TIRET;

        return [
            Stat::make(
                'Étudiants inscrits',
                EtatMesure::aUneValeur($etatEffectifs)
                    ? number_format($kpis['total_students'], 0, ',', ' ')
                    : $tiret,
            )
                ->description(
                    EtatMesure::aUneValeur($etatEffectifs)
                        ? trim($inscDeltaStr . ' vs année précédente' . ($mEffectifs ? ' · ' . $mEffectifs : ''))
                        : EtatMesure::absenceGroupe()
                )
                ->descriptionIcon(EtatMesure::aUneValeur($etatEffectifs) ? $inscIcon : 'heroicon-o-question-mark-circle')
                ->color(match (true) {
                    ! EtatMesure::aUneValeur($etatEffectifs) => 'gray',
                    $inscDelta >= 0 => 'success',
                    default => 'warning',
                }),

            Stat::make(
                'Encaissés ce mois',
                EtatMesure::estMesure($etatTrends)
                    ? number_format($trends['revenue_mom']['current'] ?? 0, 0, ',', ' ') . ' F'
                    : $tiret,
            )
                ->description(
                    EtatMesure::estMesure($etatTrends)
                        ? trim($revenueDeltaStr . ' vs mois dernier' . ($mTrends ? ' · ' . $mTrends : ''))
                        : EtatMesure::absenceGroupe()
                )
                ->descriptionIcon(EtatMesure::estMesure($etatTrends) ? $revenueIcon : 'heroicon-o-question-mark-circle')
                ->color($revenueColor),

            Stat::make(
                'Taux de recouvrement',
                EtatMesure::estMesure($etatFinances) ? $collectionRate . '%' : $tiret,
            )
                ->description(
                    EtatMesure::estMesure($etatFinances)
                        ? number_format($kpis['total_revenue_collected'] ?? 0, 0, ',', ' ')
                            . ' F sur ' . number_format($kpis['total_revenue_expected'] ?? 0, 0, ',', ' ') . ' F'
                            . ($mFinances ? ' · ' . $mFinances : '')
                        : EtatMesure::absenceGroupe()
                )
                ->descriptionIcon(EtatMesure::estMesure($etatFinances) ? 'heroicon-o-chart-bar' : 'heroicon-o-question-mark-circle')
                ->color($collectionColor),

            // Le piege de tout l'ecran tenait ici. La couleur testait
            // `$impayes30j > 0 ? danger : success` : quand plus rien n'etait
            // mesure, la tuile passait au VERT et annoncait zero dossier a
            // relancer. Un total non mesure est gris, jamais « success ».
            Stat::make(
                'Impayés > 30 jours',
                $agingMesure ? number_format($impayes30j, 0, ',', ' ') . ' F' : $tiret,
            )
                ->description(
                    $agingMesure
                        ? $impayes30jCount . ' dossier' . ($impayes30jCount > 1 ? 's' : '') . ' à relancer'
                            . ($mAging ? ' · ' . $mAging : '')
                        : EtatMesure::absenceGroupe()
                )
                ->descriptionIcon($agingMesure ? 'heroicon-o-exclamation-triangle' : 'heroicon-o-question-mark-circle')
                ->color(match (true) {
                    ! $agingMesure => 'gray',
                    $impayes30j > 0 => 'danger',
                    default => 'success',
                }),

            Stat::make(
                'Taux de présence',
                $attendanceMesure ? $attendance . '%' : $tiret,
            )
                // Les cinq autres tuiles CONCATENENT leur mention (« · ») ;
                // celle-ci la substituait au libelle. Le directeur perdait la
                // nature du chiffre — une moyenne ponderee — exactement quand
                // le perimetre ampute la rend la plus delicate a lire.
                ->description($attendanceMesure
                    ? 'moyenne pondérée groupe' . ($mAssiduite ? ' · ' . $mAssiduite : '')
                    : EtatMesure::absenceGroupe())
                ->descriptionIcon($attendanceMesure ? 'heroicon-o-check-circle' : 'heroicon-o-question-mark-circle')
                ->color($attendanceColor),

            // Le nombre d'etablissements vient de klassci_master : il est
            // toujours su, meme quand aucune base d'ecole ne repond.
            //
            // Sa description comptait le PERSONNEL mais lisait l'etat des
            // EFFECTIFS : une ecole mesuree sur ses etudiants et muette sur son
            // personnel aurait affiche un effectif de personnel faux. Et
            // « personnel non mesure » place sous un compte d'etablissements se
            // lit comme si les etablissements eux-memes etaient inconnus.
            Stat::make('Établissements', $kpis['establishment_count'] ?? 0)
                ->description(
                    EtatMesure::aUneValeur($etatPersonnel)
                        ? $kpis['total_staff'] . ' membres du personnel'
                        : 'effectif du personnel non mesuré'
                )
                ->descriptionIcon('heroicon-o-building-office-2')
                ->color('primary'),
        ];
    }
}
