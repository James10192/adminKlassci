<?php

namespace App\Filament\Group\Resources\EstablishmentResource\Pages;

use App\Filament\Group\Concerns\HasCustomHero;
use App\Filament\Group\Resources\EstablishmentResource;
use App\Services\Group\GroupKpiProvider;
use App\Services\TenantAggregationService;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

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

    /**
     * Les indicateurs de cet établissement, ou une ligne d'états explicites.
     *
     * Le repli sur `[]` était le piège : `EtatMesure::estMesure(null)` vaut
     * VRAI — c'est le contrat de rétrocompatibilité, un état absent veut dire
     * « mesuré ». Un tableau vide se relisait donc « tout mesuré, tout à
     * zéro », et le hero de la fiche affichait « 0 étudiant · 0 % — critique »
     * précisément quand la mesure avait échoué.
     *
     * `emptyKpis()` est la seule forme correcte de cette absence : elle porte
     * les quatre états et le motif.
     *
     * @return array<string,mixed>
     */
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
                } catch (\Throwable $e) {
                    Log::error("[group-portal] KPI indisponibles pour {$this->record->code}: {$e->getMessage()}");

                    return app(GroupKpiProvider::class)->emptyKpis($this->record);
                }
            }
        );
    }
}
