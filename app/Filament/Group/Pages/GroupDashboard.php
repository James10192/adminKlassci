<?php

namespace App\Filament\Group\Pages;

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
    protected static ?string $navigationIcon = 'heroicon-o-building-office-2';

    protected static ?string $title = 'Tableau de bord';

    protected static string $routePath = '/';

    public function getHeader(): ?View
    {
        return view('filament.group.partials.dashboard-hero', [
            'context' => $this->getHeroContext(),
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
            'establishment_count' => $group?->tenants()->active()->count() ?? 0,
            'academic_years' => self::extractAcademicYears($kpis),
            'last_sync' => self::lastSyncLabel($user?->group_id),
            'kpis' => $kpis,
        ];
    }

    private static function roleLabel(string $role): string
    {
        return [
            'fondateur' => 'Fondateur',
            'directeur_general' => 'Directeur Général',
            'directeur_financier' => 'Directeur Financier',
        ][$role] ?? $role;
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

    private static function lastSyncLabel(?int $groupId): string
    {
        if ($groupId === null) {
            return 'non synchronisé';
        }

        return Cache::has("group_{$groupId}_kpis")
            ? 'il y a moins de 15 min'
            : 'non synchronisé';
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('refresh')
                ->label('Actualiser')
                ->icon('heroicon-o-arrow-path')
                ->color('gray')
                ->action(function () {
                    $group = auth('group')->user()->group;
                    $service = app(TenantAggregationService::class);
                    $service->refreshGroupCache($group);
                    EstablishmentResource::forgetAlertsCache($group->id);
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
