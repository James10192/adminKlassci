<?php

namespace App\Filament\Group\Widgets;

use Filament\Widgets\Widget;
use Illuminate\Support\Facades\Cache;

class GroupWelcomeWidget extends Widget
{
    protected static bool $isLazy = false;

    protected static string $view = 'filament.group.widgets.group-welcome';

    protected int|string|array $columnSpan = 'full';

    protected static ?int $sort = 1;

    public function getGroupName(): string
    {
        return auth('group')->user()->group->name ?? 'Mon Groupe';
    }

    public function getUserName(): string
    {
        return auth('group')->user()->name ?? '';
    }

    public function getRole(): string
    {
        $roles = [
            'fondateur' => 'Fondateur',
            'directeur_general' => 'Directeur Général',
            'directeur_financier' => 'Directeur Financier',
        ];

        return $roles[auth('group')->user()->role] ?? auth('group')->user()->role;
    }

    public function getEstablishmentCount(): int
    {
        return auth('group')->user()->group->tenants()->active()->count();
    }

    public function getLastSync(): string
    {
        $groupId = auth('group')->user()->group_id;
        $key = "group_{$groupId}_kpis";

        if (Cache::has($key)) {
            return 'il y a moins de 15 min';
        }

        return 'non synchronisé';
    }
}
