<?php

namespace App\Filament\Resources;

use App\Domain\AssistantIa\ConsommationIa;
use App\Filament\Resources\ConsommationIaResource\Pages;
use App\Models\Tenant;
use Filament\Forms\Components\DatePicker;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Columns\Summarizers\Sum;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Chaque appel à un modèle d'IA, école par école : qui, quand, quel modèle, à
 * quel palier, combien. Lecture seule — les lignes viennent des écoles.
 */
class ConsommationIaResource extends Resource
{
    protected static ?string $model = ConsommationIa::class;

    protected static ?string $navigationIcon = 'heroicon-o-sparkles';

    protected static ?string $navigationLabel = 'Consommation IA';

    protected static ?string $modelLabel = 'consommation IA';

    protected static ?string $pluralModelLabel = 'Consommation IA';

    protected static ?string $navigationGroup = 'Facturation';

    public static function canViewAny(): bool
    {
        return in_array(auth()->user()?->role, ['super_admin', 'billing'], true);
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        $fcfa = fn ($state) => number_format((float) $state, (float) $state < 100 ? 2 : 0, ',', ' ') . ' FCFA';

        return $table
            ->defaultSort('survenue_at', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('survenue_at')->label('Quand')->dateTime('d/m/Y H:i')->sortable(),
                Tables\Columns\TextColumn::make('tenant.name')->label('École')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('nom_utilisateur')->label('Personne')->searchable()->placeholder('—'),
                Tables\Columns\TextColumn::make('fonction')->label('Fonction')->badge()->color('gray'),
                Tables\Columns\TextColumn::make('palier')->label('Palier')->badge()->placeholder('manuel')
                    ->color(fn (?string $state) => match ($state) { 'avance' => 'warning', 'standard' => 'info', default => 'gray' }),
                Tables\Columns\TextColumn::make('modele')->label('Modèle')->searchable(),
                Tables\Columns\TextColumn::make('tokens_entree')->label('Jetons entrée')->numeric(thousandsSeparator: ' ')->toggleable(),
                Tables\Columns\TextColumn::make('tokens_sortie')->label('Jetons sortie')->numeric(thousandsSeparator: ' ')->toggleable(),
                Tables\Columns\TextColumn::make('cout_fcfa')->label('Coût')->formatStateUsing($fcfa)->sortable()
                    ->summarize(Sum::make()->label('Total')->formatStateUsing($fcfa)),
                Tables\Columns\IconColumn::make('cout_exact')->label('Coût réel')->boolean()->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('statut')->label('Statut')->badge()
                    ->color(fn (string $state) => $state === 'ok' ? 'success' : ($state === 'echec_fournisseur' ? 'warning' : 'danger')),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('tenant_id')->label('École')
                    ->options(fn () => Tenant::orderBy('name')->pluck('name', 'id')->all()),
                Tables\Filters\SelectFilter::make('palier')->label('Palier')
                    ->options(['economique' => 'Économique', 'standard' => 'Standard', 'avance' => 'Avancé']),
                Tables\Filters\SelectFilter::make('modele')->label('Modèle')
                    ->options(fn () => ConsommationIa::query()->distinct()->orderBy('modele')->pluck('modele', 'modele')->all()),
                Tables\Filters\SelectFilter::make('fonction')->label('Fonction')
                    ->options(['question' => 'Question', 'titre' => 'Titre', 'action' => 'Action', 'import' => 'Import']),
                Tables\Filters\Filter::make('periode')
                    ->form([
                        DatePicker::make('depuis')->label('Depuis')->default(now()->startOfMonth()),
                        DatePicker::make('jusqua')->label('Jusqu\'au'),
                    ])
                    ->query(fn (Builder $q, array $data) => $q
                        ->when($data['depuis'] ?? null, fn ($q, $v) => $q->where('survenue_at', '>=', $v))
                        ->when($data['jusqua'] ?? null, fn ($q, $v) => $q->where('survenue_at', '<', \Carbon\Carbon::parse($v)->addDay())))
                    ->indicateUsing(fn (array $data) => ($data['depuis'] ?? null) ? 'Depuis le ' . \Carbon\Carbon::parse($data['depuis'])->format('d/m/Y') : null),
            ])
            ->actions([])
            ->bulkActions([]);
    }

    public static function getWidgets(): array
    {
        return [ConsommationIaResource\Widgets\ConsommationIaParEcole::class, ConsommationIaResource\Widgets\SatisfactionParModele::class];
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListConsommationIa::route('/')];
    }
}
