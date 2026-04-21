<?php

namespace App\Filament\Group\Resources\EstablishmentResource\Pages;

use App\Filament\Group\Concerns\HasCustomHero;
use App\Filament\Group\Resources\EstablishmentResource;
use App\Services\TenantAggregationService;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Cache;

class ViewEstablishment extends ViewRecord
{
    use HasCustomHero;

    protected static string $resource = EstablishmentResource::class;

    protected static ?string $title = 'Détail Établissement';

    public function getHeader(): ?View
    {
        return view('filament.group.partials.establishment-view-hero', [
            'tenant' => $this->record,
            'kpis' => $this->buildTenantKpis(),
        ]);
    }

    /** @return array<string,mixed> */
    private function buildTenantKpis(): array
    {
        // Short-TTL cache on the error path so a down tenant DB doesn't
        // re-trigger a schema-aware query on every 15s Livewire poll.
        return Cache::remember(
            "group_portal_tenant_kpis_{$this->record->id}",
            60,
            function (): array {
                try {
                    return app(TenantAggregationService::class)->getTenantKpis($this->record);
                } catch (\Throwable) {
                    return [];
                }
            }
        );
    }
}
