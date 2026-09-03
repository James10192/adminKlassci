<?php

namespace App\Filament\Widgets;

use App\Models\Tenant;
use Filament\Widgets\ChartWidget;

class TenantsByPlanChart extends ChartWidget
{
    protected static ?string $heading = 'Répartition par offre';

    protected static ?string $description = 'Établissements actifs, par offre souscrite';

    protected static ?int $sort = 4;

    protected static ?string $maxHeight = '210px';

    // Pleine largeur : en tiers de ligne, l anneau laissait deux tiers de vide
    // sous lui. Un bandeau bas et large se lit aussi bien et ferme la page.
    protected int | string | array $columnSpan = 'full';

    protected function getData(): array
    {
        $allPlans = ['free' => 0, 'essentiel' => 0, 'professional' => 0, 'elite' => 0];

        $counts = Tenant::where('status', 'active')
            ->selectRaw('plan, count(*) as count')
            ->groupBy('plan')
            ->pluck('count', 'plan')
            ->toArray();

        $plans = array_merge($allPlans, $counts);

        $planLabels = [
            'free'         => 'Free',
            'essentiel'    => 'Essentiel',
            'professional' => 'Professional',
            'elite'        => 'Elite',
        ];

        $colors = [
            'free'         => 'rgba(148, 163, 184, 0.85)',
            'essentiel'    => 'rgba(59, 130, 246, 0.85)',
            'professional' => 'rgba(16, 185, 129, 0.85)',
            'elite'        => 'rgba(245, 158, 11, 0.85)',
        ];

        $borderColors = [
            'free'         => '#94a3b8',
            'essentiel'    => '#3b82f6',
            'professional' => '#10b981',
            'elite'        => '#f59e0b',
        ];

        $labels = [];
        $data = [];
        $backgroundColor = [];
        $borderColor = [];

        foreach ($planLabels as $key => $label) {
            $labels[] = $label;
            $data[] = $plans[$key] ?? 0;
            $backgroundColor[] = $colors[$key];
            $borderColor[] = $borderColors[$key];
        }

        return [
            'datasets' => [
                [
                    'label' => 'Établissements',
                    'data' => $data,
                    'backgroundColor' => $backgroundColor,
                    'borderColor' => $borderColor,
                    'borderWidth' => 2,
                    'hoverOffset' => 6,
                ],
            ],
            'labels' => $labels,
        ];
    }

    protected function getType(): string
    {
        return 'doughnut';
    }

    protected function getOptions(): array
    {
        return [
            'plugins' => [
                'legend' => [
                    'display' => true,
                    'position' => 'bottom',
                    'labels' => [
                        'padding' => 16,
                        'usePointStyle' => true,
                        'pointStyle' => 'circle',
                        'font' => ['size' => 12, 'weight' => '600'],
                    ],
                ],
            ],
            // Filament pose des échelles par défaut, pensées pour les
            // histogrammes. Sur un anneau elles s'affichent quand même : la
            // capture de production montre un axe de 0 à 1 derrière le
            // graphique, qui ne mesure rien.
            'scales' => [
                'x' => ['display' => false],
                'y' => ['display' => false],
            ],
            'cutout' => '68%',
            'maintainAspectRatio' => false,
            'responsive' => true,
        ];
    }
}
