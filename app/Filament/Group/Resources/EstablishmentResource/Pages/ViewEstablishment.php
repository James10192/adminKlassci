<?php

namespace App\Filament\Group\Resources\EstablishmentResource\Pages;

use App\Filament\Group\Resources\EstablishmentResource;
use App\Services\TenantAggregationService;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Contracts\View\View;

class ViewEstablishment extends ViewRecord
{
    protected static string $resource = EstablishmentResource::class;

    protected static ?string $title = 'Détail Établissement';

    public function getHeader(): ?View
    {
        return view('filament.group.partials.establishment-view-hero', [
            'tenant' => $this->record,
            'kpis' => $this->buildTenantKpis(),
        ]);
    }

    public function getHeading(): string
    {
        return '';
    }

    /** @return array<string,mixed> */
    private function buildTenantKpis(): array
    {
        try {
            return app(TenantAggregationService::class)->getTenantKpis($this->record);
        } catch (\Throwable) {
            return [];
        }
    }
}
