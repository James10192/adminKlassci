<?php

namespace App\Filament\Resources\ConsommationIaResource\Widgets;

use App\Domain\AssistantIa\ConsommationIa;
use App\Models\Tenant;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;

/**
 * Le mois en cours, école par école : ce que l'IA a coûté, rapporté au budget.
 * Montré sur la page Consommation IA (pas sur le tableau de bord général).
 */
class ConsommationIaParEcole extends TableWidget
{
    protected static ?string $heading = 'Ce mois-ci, par école';

    protected int | string | array $columnSpan = 'full';

    public static function canView(): bool
    {
        return in_array(auth()->user()?->role, ['super_admin', 'billing'], true);
    }

    public function table(Table $table): Table
    {
        $mois = now()->startOfMonth();
        $precedent = now()->subMonthNoOverflow()->startOfMonth();
        $somme = fn (string $depuis, ?string $avant = null) => ConsommationIa::query()
            ->selectRaw('COALESCE(SUM(cout_fcfa), 0)')
            ->whereColumn('tenant_ai_usages.tenant_id', 'tenants.id')
            ->where('survenue_at', '>=', $depuis)
            ->when($avant, fn ($q) => $q->where('survenue_at', '<', $avant));

        $fcfa = fn ($state) => number_format((float) $state, (float) $state < 100 ? 2 : 0, ',', ' ') . ' FCFA';

        return $table
            ->query(fn (): Builder => Tenant::query()
                ->select('tenants.*')
                ->selectSub($somme($mois->toDateTimeString()), 'ia_mois')
                ->selectSub($somme($precedent->toDateTimeString(), $mois->toDateTimeString()), 'ia_mois_precedent')
                // Une réponse = une ligne « question » qui n'est pas un modèle abandonné pour le suivant.
                ->selectSub(ConsommationIa::query()->selectRaw('COUNT(*)')
                    ->whereColumn('tenant_ai_usages.tenant_id', 'tenants.id')
                    ->where('fonction', 'question')->where('statut', '!=', 'echec_fournisseur')
                    ->where('survenue_at', '>=', $mois), 'ia_echanges'))
            ->defaultSort('ia_mois', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('name')->label('École')->searchable(),
                Tables\Columns\TextColumn::make('ia_mois')->label('Dépensé ce mois')->formatStateUsing($fcfa)->sortable(),
                Tables\Columns\TextColumn::make('ia_mois_precedent')->label('Mois dernier')->formatStateUsing($fcfa)->sortable(),
                Tables\Columns\TextColumn::make('ia_echanges')->label('Réponses de Nanan')->numeric(thousandsSeparator: ' ')->sortable(),
                Tables\Columns\TextColumn::make('ai_monthly_budget_fcfa')->label('Budget')
                    ->formatStateUsing(fn ($state) => $state === null ? 'réglage de l\'école' : ((float) $state > 0 ? number_format((float) $state, 0, ',', ' ') . ' FCFA' : 'sans limite'))
                    ->placeholder('réglage de l\'école'),
                Tables\Columns\TextColumn::make('etat_budget')->label('Budget consommé')->badge()
                    ->state(fn (Tenant $t) => self::part($t))
                    ->color(fn (?string $state) => match (true) {
                        $state === null => 'gray',
                        (int) $state >= 120 => 'danger',
                        (int) $state >= 100 => 'warning',
                        default => 'success',
                    })
                    ->formatStateUsing(fn (?string $state) => $state === null ? '—' : $state . ' %')
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('ai_usage_synced_at')->label('Relevé')->since()->placeholder('jamais'),
            ])
            ->paginated([10, 25, 50]);
    }

    /** Part du budget consommée ce mois, en %, ou null sans budget fixé par le master. */
    private static function part(Tenant $t): ?string
    {
        $budget = (float) $t->ai_monthly_budget_fcfa;

        return $budget > 0 ? (string) (int) round(((float) $t->ia_mois) / $budget * 100) : null;
    }
}
