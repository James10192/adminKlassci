<?php

namespace App\Filament\Resources\RetourAssistantResource\Widgets;

use App\Domain\Care\Retours\Enums\AvisAssistant;
use App\Domain\Care\Retours\Models\RetourAssistant;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\Gate;

/** Le taux de 👍 sur la fenêtre configurée, toutes écoles confondues. */
class SatisfactionNanan extends StatsOverviewWidget
{
    protected static ?string $pollingInterval = null;

    public static function canView(): bool
    {
        return Gate::allows('support.tickets.view');
    }

    protected function getStats(): array
    {
        $jours = (int) config('care.retours_assistant.satisfaction_jours', 30);
        $parAvis = RetourAssistant::query()
            ->where('created_at', '>=', now()->subDays($jours))
            ->selectRaw('avis, count(*) as n')
            ->groupBy('avis')
            ->pluck('n', 'avis');

        $utiles = (int) ($parAvis[AvisAssistant::Utile->value] ?? 0);
        $pasUtiles = (int) ($parAvis[AvisAssistant::PasUtile->value] ?? 0);
        $total = $utiles + $pasUtiles;
        $nonTraites = RetourAssistant::where('avis', AvisAssistant::PasUtile->value)->nonTraites()->count();

        return [
            // Sans retour, pas de taux : « 0 % » dirait que tout est raté.
            Stat::make("Satisfaction ({$jours} j)", $total > 0 ? round(100 * $utiles / $total).' %' : '—')
                ->description($total > 0 ? "{$utiles} 👍 sur {$total} retours" : 'Aucun retour sur la période'),
            Stat::make("👎 ({$jours} j)", (string) $pasUtiles),
            Stat::make('👎 à lire', (string) $nonTraites)->color($nonTraites > 0 ? 'danger' : 'success'),
        ];
    }
}
