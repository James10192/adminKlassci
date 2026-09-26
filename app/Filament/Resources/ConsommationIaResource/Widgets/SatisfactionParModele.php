<?php

namespace App\Filament\Resources\ConsommationIaResource\Widgets;

use App\Domain\AssistantIa\ConsommationIa;
use App\Domain\AssistantIa\RetourIa;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;

/**
 * Ce que les écoles pensent de chaque modèle (avis 👍 / 👎 des 30 derniers
 * jours), à côté de ce qu'une réponse coûte. C'est la base pour ranger un
 * modèle dans un palier : le moins cher qui satisfait.
 */
class SatisfactionParModele extends TableWidget
{
    protected static ?string $heading = 'Satisfaction par modèle (30 jours)';

    protected int | string | array $columnSpan = 'full';

    public static function canView(): bool
    {
        return in_array(auth()->user()?->role, ['super_admin', 'billing'], true);
    }

    public function table(Table $table): Table
    {
        $depuis = now()->subDays(30);

        return $table
            ->query(fn (): Builder => RetourIa::query()
                ->selectRaw('MIN(id) as id, modele')
                ->selectRaw("SUM(CASE WHEN avis = 'utile' THEN 1 ELSE 0 END) as utiles")
                ->selectRaw("SUM(CASE WHEN avis = 'pas_utile' THEN 1 ELSE 0 END) as pas_utiles")
                ->selectRaw("SUM(CASE WHEN raison = 'faux' THEN 1 ELSE 0 END) as fausses")
                ->selectSub(ConsommationIa::query()->selectRaw('AVG(cout_fcfa)')
                    ->whereColumn('tenant_ai_usages.modele', 'tenant_ai_feedbacks.modele')
                    ->where('fonction', 'question')->where('survenue_at', '>=', $depuis), 'cout_moyen')
                ->where('survenue_at', '>=', $depuis)
                ->whereNotNull('modele')
                ->groupBy('modele'))
            ->defaultSort('pas_utiles', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('modele')->label('Modèle'),
                Tables\Columns\TextColumn::make('utiles')->label('Utile')->numeric()->sortable(),
                Tables\Columns\TextColumn::make('pas_utiles')->label('Pas utile')->numeric()->sortable(),
                Tables\Columns\TextColumn::make('satisfaction')->label('Satisfaction')->badge()
                    ->state(fn ($record) => ($total = (int) $record->utiles + (int) $record->pas_utiles) > 0 ? (int) round($record->utiles / $total * 100) : null)
                    ->formatStateUsing(fn ($state) => $state === null ? '—' : $state . ' %')
                    ->color(fn ($state) => match (true) {
                        $state === null => 'gray',
                        $state >= 80 => 'success',
                        $state >= 60 => 'warning',
                        default => 'danger',
                    }),
                Tables\Columns\TextColumn::make('fausses')->label('Dont « information fausse »')->numeric(),
                Tables\Columns\TextColumn::make('cout_moyen')->label('Coût moyen d\'une réponse')
                    ->formatStateUsing(fn ($state) => $state === null ? '—' : number_format((float) $state, 2, ',', ' ') . ' FCFA'),
            ])
            ->paginated(false);
    }
}
