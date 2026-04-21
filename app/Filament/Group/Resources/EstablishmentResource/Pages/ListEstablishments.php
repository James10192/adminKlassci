<?php

namespace App\Filament\Group\Resources\EstablishmentResource\Pages;

use App\Filament\Group\Resources\EstablishmentResource;
use App\Services\TenantAggregationService;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Contracts\View\View;

class ListEstablishments extends ListRecords
{
    protected static string $resource = EstablishmentResource::class;

    protected static ?string $title = 'Mes Établissements';

    public function getHeader(): ?View
    {
        return view('filament.group.partials.establishments-hero', [
            'context' => $this->buildHeroContext(),
        ]);
    }

    public function getHeading(): string
    {
        return '';
    }

    /**
     * @return array{total_students: int, total_staff: int, establishment_count: int, avg_rate: float}
     */
    private function buildHeroContext(): array
    {
        $group = auth('group')->user()?->group;
        $kpis = $group ? app(TenantAggregationService::class)->getGroupKpis($group) : [];

        return [
            'total_students' => (int) ($kpis['total_students'] ?? 0),
            'total_staff' => (int) ($kpis['total_staff'] ?? 0),
            'establishment_count' => (int) ($kpis['establishment_count'] ?? 0),
            'avg_rate' => (float) ($kpis['collection_rate'] ?? 0),
        ];
    }
}
