<?php

namespace App\Filament\Forms\Saas;

use App\Models\Group;
use App\Models\SaasProduct;
use App\Models\Tenant;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Get;

/**
 * Formulaire partagé entre inventaire (tenant déjà choisi) et dossiers.
 * Les options viennent uniquement des modèles, jamais de noms d'écoles
 * ou de codes de produits propres à un client.
 */
final class ReconciliationDraftFields
{
    /** @return array<int, \Filament\Forms\Components\Component> */
    public static function make(bool $withTenantSelector = true): array
    {
        $fields = [];
        if ($withTenantSelector) {
            $fields[] = Select::make('legacy_tenant_id')
                ->label('Instance historique')
                ->required()
                ->searchable()
                ->getSearchResultsUsing(fn (string $search): array =>
                    Tenant::query()
                        ->whereDoesntHave('mappingReview')
                        ->where(fn ($query) => $query
                            ->where('code', 'like', '%'.$search.'%')
                            ->orWhere('name', 'like', '%'.$search.'%'))
                        ->orderBy('code')->limit(30)->pluck('code', 'id')->all())
                ->getOptionLabelUsing(fn ($value): ?string =>
                    Tenant::query()->whereKey($value)->value('code'))
                ->helperText("Seules les instances actives et sans dossier peuvent être proposées.");
        }

        $fields[] = Select::make('kind')
            ->label("Nature de l'instance")
            ->required()->live()
            ->options(config('saas.instance_kinds', []))
            ->helperText("Choix explicite du personnel : aucune déduction automatique du sous-domaine.");

        $fields[] = Select::make('product_id')
            ->label('Produit scolaire')
            ->searchable()->required(fn (Get $get) => $get('kind') === 'school')
            ->options(fn () => SaasProduct::query()
                ->where('is_active', true)->orderBy('name')->pluck('name', 'id')->all())
            ->visible(fn (Get $get): bool => $get('kind') === 'school')
            ->helperText("Seuls les produits activés dans le catalogue apparaissent. Aucun tarif n'est modifié.");

        $fields[] = Select::make('proposed_group_id')
            ->label('Groupe commercial proposé (facultatif)')
            ->searchable()
            ->getSearchResultsUsing(fn (string $search): array =>
                Group::query()->where(fn ($query) => $query
                    ->where('name', 'like', '%'.$search.'%')
                    ->orWhere('code', 'like', '%'.$search.'%'))
                    ->orderBy('name')->limit(30)->pluck('name', 'id')->all())
            ->getOptionLabelUsing(fn ($value): ?string =>
                Group::query()->whereKey($value)->value('name'))
            ->placeholder('Aucun groupe — établissement indépendant')
            ->visible(fn (Get $get): bool => $get('kind') === 'school')
            ->helperText("Un écart avec le groupe historique est signalé au contrôleur ; aucun accès SSO n'est accordé.");

        $fields[] = Textarea::make('evidence_reference')
            ->label('Référence de la justification')
            ->required()->minLength(6)->maxLength(255)->rows(2)
            ->helperText("Référence interne vérifiable, sans copier de mots de passe, contrats complets ou données d'élèves.");

        return $fields;
    }
}
