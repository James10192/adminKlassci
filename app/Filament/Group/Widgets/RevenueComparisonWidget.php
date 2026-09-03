<?php

namespace App\Filament\Group\Widgets;

use App\Filament\Group\Concerns\PeriodAwareConcern;
use App\Services\TenantAggregationService;
use App\Support\EtatMesure;
use Filament\Widgets\ChartWidget;

class RevenueComparisonWidget extends ChartWidget
{
    use PeriodAwareConcern;

    // Keep lazy: cross-DB queries can be slow

    protected static ?string $heading = 'Revenus par établissement';

    protected static ?int $sort = 4;

    protected static ?string $pollingInterval = '300s';

    protected static ?string $maxHeight = '350px';

    // Un ChartWidget sans donnees rend un canevas vide : une carte blanche au
    // bas du tableau de bord. Cette vue dit la raison a la place du graphe.
    protected static string $view = 'filament.group.widgets.chart-ou-vide';

    /** @var array<string,mixed>|null mémo par instance : getData() et getDescription() lisaient deux fois */
    private ?array $financialsMemo = null;

    /** @return array<string,mixed> */
    private function financials(): array
    {
        if ($this->financialsMemo !== null) {
            return $this->financialsMemo;
        }

        $group = auth('group')->user()->group;

        return $this->financialsMemo = app(TenantAggregationService::class)
            ->getGroupFinancials($group, $this->currentPeriod());
    }

    protected function getData(): array
    {
        $financials = $this->financials();

        $labels = [];
        $expected = [];
        $collected = [];

        // Une base injoignable renvoyait 0 attendu / 0 encaissé, et le
        // graphique traçait deux barres a plat : une ecole sans aucune recette
        // ni aucune creance, ce qui n'existe pas. Un histogramme ne peut pas
        // dessiner « inconnu » — on ne lui donne donc que le mesure, et le
        // sous-titre dit ce qui manque.
        foreach ($financials as $data) {
            if (! EtatMesure::aUneValeur($data['etat'] ?? null)) {
                continue;
            }

            $labels[] = $data['tenant_name'];
            $expected[] = round(($data['revenue_expected'] ?? 0) / 1000000, 1); // En millions
            $collected[] = round(($data['revenue_collected'] ?? 0) / 1000000, 1);
        }

        return [
            'datasets' => [
                [
                    'label' => 'Attendu (M FCFA)',
                    'data' => $expected,
                    'backgroundColor' => 'rgba(4, 83, 203, 0.2)',
                    'borderColor' => 'rgba(4, 83, 203, 1)',
                    'borderWidth' => 2,
                ],
                [
                    'label' => 'Encaissé (M FCFA)',
                    'data' => $collected,
                    'backgroundColor' => 'rgba(16, 185, 129, 0.2)',
                    'borderColor' => 'rgba(16, 185, 129, 1)',
                    'borderWidth' => 2,
                ],
            ],
            'labels' => $labels,
        ];
    }

    /** Ce que le graphique ne montre pas — sinon un histogramme amputé se lit comme complet. */
    public function getDescription(): ?string
    {
        $financials = $this->financials();

        $total = count($financials);
        $mesures = 0;
        $manquants = [];
        foreach ($financials as $code => $data) {
            if (EtatMesure::aUneValeur($data['etat'] ?? null)) {
                $mesures++;
            } else {
                $manquants[$code] = ['motif' => $data['motif'] ?? EtatMesure::MOTIF_INJOIGNABLE];
            }
        }

        if ($total === 0) {
            return null;
        }

        // La raison vient des etablissements reellement absents, pas d'un motif
        // par defaut qui affirmerait une panne reseau non constatee.
        if ($mesures === 0) {
            $raison = EtatMesure::raisonCommune($manquants);

            return ucfirst(EtatMesure::absenceGroupe()) . ($raison ? ' — ' . $raison : '');
        }

        return EtatMesure::mentionPerimetre($mesures, $total);
    }

    protected function getType(): string
    {
        return 'bar';
    }
}
