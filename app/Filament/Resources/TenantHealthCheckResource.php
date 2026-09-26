<?php

namespace App\Filament\Resources;

use App\Filament\Resources\TenantHealthCheckResource\Pages;
use App\Filament\Resources\TenantHealthCheckResource\RelationManagers;
use App\Models\TenantHealthCheck;
use App\Support\Sante\ControleSante;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class TenantHealthCheckResource extends Resource
{
    use \App\Filament\Concerns\LibelleAvecMajusculeInitiale;

    protected static ?string $model = TenantHealthCheck::class;

    protected static ?string $navigationIcon = 'heroicon-o-exclamation-triangle';

    protected static ?string $navigationLabel = 'Anomalies relevées';

    protected static ?string $modelLabel = 'anomalie';

    protected static ?string $pluralModelLabel = 'anomalies relevées';

    protected static ?string $navigationGroup = 'Surveillance';

    protected static ?int $navigationSort = 1;

    // Afficher badge avec nombre de problèmes
    public static function getNavigationBadge(): ?string
    {
        return static::getModel()::whereIn('status', ['degraded', 'unhealthy'])
            ->where('created_at', '>=', now()->subHours(1))
            ->count() ?: null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        $count = static::getNavigationBadge();
        if (!$count) return null;

        $criticalCount = static::getModel()::where('status', 'unhealthy')
            ->where('created_at', '>=', now()->subHours(1))
            ->count();

        return $criticalCount > 0 ? 'danger' : 'warning';
    }

    // Query par défaut : problèmes non résolus (dernières 72h)
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->whereIn('status', ['degraded', 'unhealthy'])
            ->where('checked_at', '>=', now()->subHours(72))
            ->latest('checked_at');
    }

    public static function form(Form $form): Form
    {
        // Formulaire en lecture seule - les health checks sont générés automatiquement
        return $form->schema([]);
    }

    public static function canCreate(): bool
    {
        return false; // Pas de création manuelle
    }

    public static function canEdit($record): bool
    {
        return false; // Pas d'édition manuelle
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('tenant.name')
                    ->label('Établissement')
                    ->searchable()
                    ->sortable()
                    ->url(fn ($record) => route('filament.admin.resources.tenants.view', $record->tenant_id))
                    ->icon('heroicon-o-building-office-2')
                    ->color('primary')
                    ->weight('bold'),

                Tables\Columns\TextColumn::make('check_type')
                    ->label('Type')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => ControleSante::libelleType($state))
                    ->color('gray'),

                Tables\Columns\TextColumn::make('status')
                    ->label('Statut')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => ControleSante::libelleStatut($state))
                    ->color(fn (string $state): string => ControleSante::couleurStatut($state))
                    ->icon(fn (string $state): string => ControleSante::iconeStatut($state)),

                Tables\Columns\TextColumn::make('details')
                    ->label('Problème')
                    ->limit(60)
                    ->tooltip(fn ($record) => $record->details)
                    ->wrap()
                    ->searchable(),

                Tables\Columns\TextColumn::make('response_time_ms')
                    ->label('Temps de réponse')
                    ->formatStateUsing(fn ($state) => $state ? $state . ' ms' : '—')
                    ->color(fn ($state) => match (true) {
                        $state === null => 'gray',
                        $state < 500 => 'success',
                        $state < 1000 => 'warning',
                        default => 'danger',
                    })
                    ->sortable(),

                Tables\Columns\TextColumn::make('checked_at')
                    ->label('Détecté')
                    ->dateTime('d/m/Y H:i')
                    ->since()
                    ->sortable()
                    ->description(fn ($record) => $record->checked_at->format('d/m/Y à H:i')),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->options(array_intersect_key(ControleSante::STATUTS, array_flip(['degraded', 'unhealthy'])))
                    ->default('unhealthy'), // Par défaut, afficher les critiques

                Tables\Filters\SelectFilter::make('check_type')
                    ->label('Type de contrôle')
                    ->options(ControleSante::TYPES),

                Tables\Filters\SelectFilter::make('tenant_id')
                    ->relationship('tenant', 'name')
                    ->searchable()
                    ->preload(),

                Tables\Filters\Filter::make('recent')
                    ->label('Dernière heure')
                    ->query(fn (Builder $query): Builder => $query->where('created_at', '>=', now()->subHour()))
                    ->default(),
            ])
            ->actions([
                Tables\Actions\Action::make('rerun')
                    ->label('Re-vérifier')
                    ->icon('heroicon-o-arrow-path')
                    ->color('info')
                    ->action(function ($record) {
                        \Artisan::call('tenant:health-check', [
                            'tenant' => $record->tenant->code,
                        ]);

                        \Filament\Notifications\Notification::make()
                            ->success()
                            ->title('Vérification relancée')
                            ->body("Vérification exécutée pour {$record->tenant->name}")
                            ->send();
                    }),

                Tables\Actions\Action::make('view_tenant')
                    ->label("Voir l'établissement")
                    ->icon('heroicon-o-eye')
                    ->url(fn ($record) => route('filament.admin.resources.tenants.view', $record->tenant_id))
                    ->color('gray'),

                Tables\Actions\DeleteAction::make()
                    ->label('Résoudre')
                    ->modalHeading('Marquer comme résolu')
                    ->modalDescription("Cette action va archiver ce problème. L'établissement sera revérifié au prochain passage de la sonde.")
                    ->successNotificationTitle('Problème marqué comme résolu'),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make()
                        ->label('Marquer comme résolus'),
                ]),
            ])
            ->defaultSort('checked_at', 'desc')
            ->poll('30s') // Rafraîchissement auto toutes les 30s
            ->emptyStateHeading('Rien à signaler')
            ->emptyStateDescription('Aucune anomalie dans les relevés de la période retenue.')
            ->emptyStateIcon('heroicon-o-check-circle');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ManageTenantHealthChecks::route('/'),
        ];
    }
}
