<?php

namespace App\Filament\Widgets;

use App\Domain\AssistantIa\ConsommationIa;
use App\Models\Tenant;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Ce que coûte l'assistant IA de toutes les écoles, ce mois-ci, par rapport au
 * mois dernier à la même date. Couleur seulement quand elle dit un état : une
 * école au-delà de son budget.
 */
class ConsommationIaOverview extends BaseWidget
{
    protected ?string $heading = 'Assistant IA';

    protected static ?int $sort = 5;

    protected int | string | array $columnSpan = 'full';

    public static function canView(): bool
    {
        return in_array(auth()->user()?->role, ['super_admin', 'billing'], true);
    }

    protected function getStats(): array
    {
        $debut = now()->startOfMonth();
        // Même nombre de jours écoulés le mois dernier : comparer un mois entamé à un mois plein ne dit rien.
        $avantDebut = now()->subMonthNoOverflow()->startOfMonth();
        $avantFin = now()->subMonthNoOverflow();

        $mois = (float) ConsommationIa::where('survenue_at', '>=', $debut)->sum('cout_fcfa');
        $avant = (float) ConsommationIa::whereBetween('survenue_at', [$avantDebut, $avantFin])->sum('cout_fcfa');
        $questions = ConsommationIa::where('survenue_at', '>=', $debut)->where('fonction', 'question')->count();

        $parEcole = ConsommationIa::where('survenue_at', '>=', $debut)
            ->selectRaw('tenant_id, SUM(cout_fcfa) as total')->groupBy('tenant_id')->pluck('total', 'tenant_id');
        $auDela = Tenant::whereNotNull('ai_monthly_budget_fcfa')->where('ai_monthly_budget_fcfa', '>', 0)->get()
            ->filter(fn (Tenant $t) => (float) ($parEcole[$t->id] ?? 0) >= (float) $t->ai_monthly_budget_fcfa)->count();

        $variation = $avant > 0 ? round(($mois - $avant) / $avant * 100) : null;

        return [
            Stat::make('Coût IA ce mois', number_format($mois, 0, ',', ' '))
                ->description('FCFA · ' . ($variation === null ? 'pas de comparaison' : (($variation >= 0 ? '+' : '') . $variation . ' % vs même période du mois dernier')))
                ->descriptionIcon('heroicon-m-sparkles')
                ->color('gray')
                ->url(route('filament.admin.resources.consommation-ias.index')),
            Stat::make('Appels au modèle', number_format($questions, 0, ',', ' '))
                ->description('questions posées à Nanan ce mois')
                ->color('gray'),
            Stat::make('Écoles au-delà du budget', $auDela)
                ->description($auDela > 0 ? 'palier économique ou pause' : 'toutes sous leur budget')
                ->color($auDela > 0 ? 'warning' : 'gray'),
        ];
    }
}
