<?php

namespace App\Filament\Forms\Group;

use App\Models\Tenant;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;

/** Same searchable, bounded and audited association form on the page and table. */
final class AssociateTenantFields
{
    public static function make(): array
    {
        return [
            Select::make('tenant_id')
                ->label('Établissement existant')
                ->placeholder('Rechercher un nom ou un code…')
                ->searchable()
                ->required()
                ->getSearchResultsUsing(fn (string $search): array => Tenant::query()
                    ->whereNull('group_id')
                    ->where(fn ($query) => $query
                        ->where('name', 'like', '%'.$search.'%')
                        ->orWhere('code', 'like', '%'.$search.'%'))
                    ->orderBy('name')
                    ->limit(30)
                    ->get(['id', 'code', 'name'])
                    ->mapWithKeys(fn (Tenant $tenant) => [
                        $tenant->id => $tenant->name.' · '.$tenant->code,
                    ])->all())
                ->getOptionLabelUsing(function ($id): ?string {
                    $tenant = Tenant::query()
                        ->whereNull('group_id')
                        ->whereKey($id)
                        ->first(['id', 'code', 'name']);

                    return $tenant ? $tenant->name.' · '.$tenant->code : null;
                })
                ->helperText('Seules les instances existantes et sans groupe peuvent être ajoutées. Pour une école non enregistrée, utilisez le provisioning dédié.'),
            Textarea::make('reason')
                ->label('Motif du rattachement')
                ->required()
                ->minLength(10)
                ->maxLength(500)
                ->rows(3)
                ->placeholder('Référence de la validation commerciale ou administrative')
                ->helperText("Cette modification de visibilité dans le portail Groupe est auditée. Aucun abonnement n'est modifié."),
        ];
    }
}
