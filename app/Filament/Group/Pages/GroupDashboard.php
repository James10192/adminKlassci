<?php

namespace App\Filament\Group\Pages;

use App\Enums\GroupMemberRole;
use App\Domain\Exports\Reports\EtatEtablissementsReport;
use App\Filament\Group\Concerns\HasCustomHero;
use App\Filament\Group\Concerns\HasReportActions;
use App\Filament\Group\Resources\EstablishmentResource;
use App\Services\TenantAggregationService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Dashboard;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;

class GroupDashboard extends Dashboard
{
    use HasCustomHero;
    use HasReportActions;

    protected static ?string $navigationIcon = 'heroicon-o-building-office-2';

    protected static ?string $title = 'Tableau de bord';

    protected static string $routePath = '/';

    /**
     * Le hero remplace l'entete de Filament — actions comprises.
     *
     * Filament ne rend sa propre barre d'actions que lorsque `getHeader()`
     * retourne null. Les trois actions declarees plus bas (etat des
     * etablissements, actualiser, verifier les alertes) etaient donc
     * construites a chaque rendu et n'apparaissaient nulle part. On les passe
     * au hero, qui a un emplacement pour elles.
     */
    public function getHeader(): ?View
    {
        return view('filament.group.partials.dashboard-hero', [
            'context' => $this->getHeroContext(),
            'actions' => $this->getCachedHeaderActions(),
        ]);
    }

    /**
     * @return array{
     *     group_name: string,
     *     user_name: string,
     *     role: string,
     *     establishment_count: int,
     *     academic_years: list<string>,
     *     last_sync: string,
     *     perimetre: array<string,mixed>,
     *     kpis: array<string,mixed>,
     * }
     */
    private function getHeroContext(): array
    {
        $user = auth('group')->user();
        $group = $user?->group;
        $service = app(TenantAggregationService::class);
        $kpis = $group ? $service->getGroupKpis($group) : [];

        return [
            'group_name' => $group->name ?? 'Mon Groupe',
            'user_name' => $user->name ?? '',
            'role' => self::roleLabel($user->role ?? ''),
            'establishment_count' => self::cachedTenantCount($group),
            'academic_years' => self::extractAcademicYears($kpis),
            // L'age reel de la mesure, pas une phrase fixe. La puce annoncait
            // « il y a moins de 15 min » alors que le cache des KPI vit 300
            // secondes : le libelle etait faux d'un facteur trois, et ne
            // dependait meme pas de l'heure du calcul.
            'last_sync' => self::ageMesure($kpis),
            'perimetre' => $kpis['perimetre'] ?? [],
            'kpis' => $kpis,
        ];
    }

    /**
     * Depuis quand les chiffres affiches ont-ils ete calcules.
     *
     * `computeGroupKpis()` horodate son resultat ; comme le tableau entier est
     * mis en cache, l'horodatage vieillit avec lui et dit donc l'age reel de ce
     * que le fondateur a sous les yeux.
     */
    private static function ageMesure(array $kpis): string
    {
        $calculeA = $kpis['computed_at'] ?? null;

        if (! $calculeA) {
            return 'non mesuré';
        }

        try {
            return \Illuminate\Support\Carbon::parse($calculeA)->locale('fr')->diffForHumans();
        } catch (\Exception) {
            return 'non mesuré';
        }
    }

    private static function cachedTenantCount(?\App\Models\Group $group): int
    {
        if ($group === null) {
            return 0;
        }

        // 2 min TTL — avoids a COUNT() query on every Filament render / Livewire poll.
        return (int) Cache::remember(
            "group_{$group->id}_tenant_count",
            120,
            fn () => $group->tenants()->active()->count()
        );
    }

    /**
     * Reads the label from the enum rather than a local table.
     *
     * A hardcoded map silently degrades: a role added to GroupMemberRole but
     * forgotten here falls through to `?? $role` and greets the member with a
     * raw slug — which is exactly what a Directeur Général Adjoint saw. The
     * enum is the single source of truth for every downstream label.
     */
    private static function roleLabel(string $role): string
    {
        return GroupMemberRole::tryFrom($role)?->label() ?? $role;
    }

    /** @param array<string,mixed> $kpis @return list<string> */
    private static function extractAcademicYears(array $kpis): array
    {
        $years = [];
        foreach ($kpis['establishments'] ?? [] as $establishment) {
            $year = $establishment['academic_year'] ?? null;
            if ($year && $year !== 'N/A' && ! in_array($year, $years, true)) {
                $years[] = $year;
            }
        }

        return $years;
    }

    protected function getHeaderActions(): array
    {
        return [
            $this->actionsRapport(
                'etat_etablissements',
                'État des établissements',
                fn () => new EtatEtablissementsReport(
                    app(TenantAggregationService::class)->getGroupKpis(auth('group')->user()->group),
                    (string) auth('group')->user()->group->name,
                    \App\Support\Period\PeriodFactory::default()->label(),
                ),
                'heroicon-o-building-office-2',
            ),
            Action::make('refresh')
                ->label('Actualiser')
                ->icon('heroicon-o-arrow-path')
                ->color('gray')
                ->action(function () {
                    $group = auth('group')->user()->group;
                    $service = app(TenantAggregationService::class);
                    $service->refreshGroupCache($group);
                    EstablishmentResource::forgetAlertsCache($group->id);
                    Cache::forget("group_{$group->id}_tenant_count");
                    $service->getGroupKpis($group);

                    Notification::make()
                        ->title('Données actualisées')
                        ->success()
                        ->send();
                }),
            Action::make('check_alerts')
                ->label('Vérifier alertes')
                ->icon('heroicon-o-bell-alert')
                ->color('warning')
                ->action(function () {
                    $group = auth('group')->user()->group;
                    Artisan::call('group:alert-check', ['--group' => $group->code]);
                    EstablishmentResource::forgetAlertsCache($group->id);

                    Notification::make()
                        ->title('Alertes vérifiées')
                        ->success()
                        ->send();
                }),
        ];
    }

    public function getWidgets(): array
    {
        return [
            \App\Filament\Group\Widgets\KpiOverviewWidget::class,
            \App\Filament\Group\Widgets\GroupAlertsWidget::class,
            \App\Filament\Group\Widgets\GroupAgingWidget::class,
            \App\Filament\Group\Widgets\EstablishmentCardsWidget::class,
            \App\Filament\Group\Widgets\RevenueComparisonWidget::class,
            \App\Filament\Group\Widgets\EnrollmentWidget::class,
        ];
    }

    public function getColumns(): int|string|array
    {
        return 2;
    }
}
