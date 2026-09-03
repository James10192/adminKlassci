<?php

namespace App\Filament\Group\Widgets;

use App\Services\Group\GroupSsoUrlBuilder;
use App\Services\TenantAggregationService;
use Filament\Widgets\Widget;

class EstablishmentCardsWidget extends Widget
{
    protected static string $view = 'filament.group.widgets.establishment-cards';

    protected int|string|array $columnSpan = 'full';

    protected static ?int $sort = 3;

    protected static ?string $pollingInterval = '300s';

    public function getEstablishments(): array
    {
        $group = auth('group')->user()->group;
        $kpis = app(TenantAggregationService::class)->getGroupKpis($group);

        return $kpis['establishments'] ?? [];
    }

    /**
     * L'URL signée d'un établissement — les gardes vivent dans le service.
     *
     * Cette méthode reste PUBLIQUE parce que Livewire la rend appelable depuis
     * la console du navigateur : c'est justement pour ça que le contrôle
     * d'appartenance au groupe est dans le service, et non ici.
     */
    public function getSsoUrl(string $tenantCode, string $redirectTo = '/'): ?string
    {
        return app(GroupSsoUrlBuilder::class)->pour($tenantCode, $redirectTo);
    }
}
