<?php

namespace App\Filament\Widgets;

use App\Models\Tenant;
use Filament\Widgets\ChartWidget;

class TenantsByPlanChart extends ChartWidget
{
    protected static ?string $heading = 'Répartition des Établissements par Plan';

    protected static ?int $sort = 4;

    protected function getData(): array
    {
        // Compter les tenants par plan
        $plans = Tenant::where('status', 'active')
            ->selectRaw('plan, count(*) as count')
            ->groupBy('plan')
            ->pluck('count', 'plan')
            ->toArray();

        // Labels en français
        $planLabels = [
            'free' => 'Free',
            'essentiel' => 'Essentiel',
            'professional' => 'Professional',
            'elite' => 'Elite',
        ];

        $labels = [];
        $data = [];
        $backgroundColor = [];

        // Couleurs par plan
        $colors = [
            'free' => 'rgba(156, 163, 175, 0.8)',      // Gray
            'essentiel' => 'rgba(59, 130, 246, 0.8)',  // Blue
            'professional' => 'rgba(16, 185, 129, 0.8)', // Green
            'elite' => 'rgba(245, 158, 11, 0.8)',      // Amber
        ];

        foreach ($planLabels as $key => $label) {
            $labels[] = $label;
            $data[] = $plans[$key] ?? 0;
            $backgroundColor[] = $colors[$key];
        }

        return [
            'datasets' => [
                [
                    'label' => 'Nombre d\'établissements',
                    'data' => $data,
                    'backgroundColor' => $backgroundColor,
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
                ],
            ],
        ];
    }
}
