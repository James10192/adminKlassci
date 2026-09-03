<?php

namespace App\Filament\Widgets;

use App\Models\Tenant;
use App\Models\TenantHealthCheck;
use App\Services\Parc\EtatParcResolver;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Carbon;

/**
 * État de santé des établissements, à l'instant présent.
 *
 * Deux corrections y ont été faites, de la même famille.
 *
 * Les tuiles portaient des sparklines inventées : une série codée en dur
 * (1, 0, 1, 2, 1, 0, valeur) ou de l'arithmétique sur le chiffre du jour
 * présentée comme un historique. Sur un écran de supervision, une courbe qui
 * ne vient pas des relevés est pire qu'inutile : elle rassure.
 *
 * Puis les établissements jamais vérifiés étaient comptés parmi les
 * opérationnels. Le décompte disait « 4 opérationnels » sur un parc où la
 * sonde n'était jamais passée. Voir App\Services\Parc\EtatParcResolver.
 *
 * `tenant_health_checks` garde bien des relevés horodatés, donc une vraie
 * tendance est calculable — mais elle demanderait un index de tête sur la
 * colonne de date, absent aujourd'hui, alors que ce widget se rafraîchit
 * toutes les 30 secondes. À faire quand l'index sera là.
 */
class TenantHealthOverview extends BaseWidget
{
    protected static ?int $sort = 1;

    protected static ?string $pollingInterval = '30s';

    protected int | string | array $columnSpan = 'full';

    protected function getColumns(): int
    {
        return 4;
    }

    protected function getStats(): array
    {
        $etablissements = Tenant::where('status', 'active')->pluck('name', 'id');
        $fraicheur = (int) config('klassci.health_freshness_minutes', 15);

        // Le dernier relevé de chaque établissement, en une requête : l'id le
        // plus élevé par tenant, puis les lignes correspondantes.
        $derniersIds = TenantHealthCheck::selectRaw('MAX(id) as id')
            ->whereIn('tenant_id', $etablissements->keys())
            ->groupBy('tenant_id')
            ->pluck('id');

        $derniers = TenantHealthCheck::whereIn('id', $derniersIds)
            ->get(['tenant_id', 'status', 'created_at'])
            ->keyBy('tenant_id');

        // Chaque établissement actif figure dans le tableau, même sans relevé :
        // c'est justement le cas qu'on veut voir.
        $releves = $etablissements->keys()->mapWithKeys(fn ($id) => [$id => [
            'statut' => $derniers[$id]->status ?? null,
            'releve_a' => $derniers[$id]->created_at ?? null,
        ]])->all();

        $r = app(EtatParcResolver::class)->repartir($releves, now(), $fraicheur);

        $dernierReleve = TenantHealthCheck::max('created_at');
        $quand = $dernierReleve
            ? 'Dernier relevé ' . Carbon::parse($dernierReleve)->diffForHumans()
            : 'La sonde n\'est jamais passée';

        $sansReleve = $r[EtatParcResolver::SANS_RELEVE];

        return [
            Stat::make('Opérationnels', $r[EtatParcResolver::OPERATIONNEL])
                ->description("Vérifiés et sains · {$quand}")
                ->descriptionIcon('heroicon-o-check-circle')
                ->color($r[EtatParcResolver::OPERATIONNEL] > 0 ? 'success' : 'gray'),

            Stat::make('À surveiller', $r[EtatParcResolver::DEGRADE])
                ->description($r[EtatParcResolver::DEGRADE] > 0
                    ? 'Dégradation relevée, sans interruption'
                    : 'Aucune dégradation relevée')
                ->descriptionIcon('heroicon-o-exclamation-triangle')
                ->color($r[EtatParcResolver::DEGRADE] > 0 ? 'warning' : 'gray'),

            Stat::make('Critiques', $r[EtatParcResolver::CRITIQUE])
                ->description($r[EtatParcResolver::CRITIQUE] > 0
                    ? 'Intervention immédiate'
                    : 'Aucun incident relevé')
                ->descriptionIcon('heroicon-o-x-circle')
                ->color($r[EtatParcResolver::CRITIQUE] > 0 ? 'danger' : 'gray'),

            // Ni bon ni mauvais : l'aveu qu'on ne sait pas. Gris, jamais vert —
            // c'est la case qui désigne les établissements à aller vérifier.
            Stat::make('Sans relevé', $sansReleve)
                ->description($sansReleve > 0
                    ? "Rien de frais depuis {$fraicheur} min — php artisan tenant:health-check --all"
                    : 'Tout le parc a un relevé frais')
                ->descriptionIcon('heroicon-o-question-mark-circle')
                ->color($sansReleve > 0 ? 'warning' : 'gray'),
        ];
    }
}
