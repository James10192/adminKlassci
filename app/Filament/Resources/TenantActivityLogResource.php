<?php

namespace App\Filament\Resources;

use App\Filament\Resources\TenantActivityLogResource\Pages;
use App\Models\TenantActivityLog;
use App\Models\Tenant;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class TenantActivityLogResource extends Resource
{
    protected static ?string $model = TenantActivityLog::class;

    protected static ?string $navigationIcon = 'heroicon-o-clipboard-document-list';

    protected static ?string $navigationLabel = 'Historique';

    protected static ?string $modelLabel = 'entrée';

    protected static ?string $pluralModelLabel = 'Journal d\'activité';

    protected static ?string $navigationGroup = 'Monitoring';

    protected static ?int $navigationSort = 2;

    public static function canCreate(): bool
    {
        return false; // Read-only
    }

    public static function form(Form $form): Form
    {
        return $form->schema([]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->query(TenantActivityLog::query()->with('tenant')->latest('performed_at'))
            ->columns([
                Tables\Columns\TextColumn::make('performed_at')
                    ->label('Date')
                    ->dateTime('d/m/Y H:i:s')
                    ->sortable()
                    ->since()
                    ->tooltip(fn ($record) => $record->performed_at?->format('d/m/Y H:i:s')),

                Tables\Columns\TextColumn::make('tenant.name')
                    ->label('Établissement')
                    ->searchable()
                    ->sortable()
                    ->url(fn ($record) => $record->tenant_id
                        ? route('filament.admin.resources.tenants.view', $record->tenant_id)
                        : null
                    )
                    ->color('primary')
                    ->icon('heroicon-o-building-office-2'),

                Tables\Columns\TextColumn::make('action')
                    ->label('Action')
                    ->badge()
                    ->color(fn (string $state): string => match (true) {
                        str_contains($state, 'deploy') => 'info',
                        str_contains($state, 'create') || str_contains($state, 'provision') => 'success',
                        str_contains($state, 'delete') || str_contains($state, 'suspend') => 'danger',
                        str_contains($state, 'update') || str_contains($state, 'health') => 'warning',
                        str_contains($state, 'backup') => 'primary',
                        default => 'gray',
                    })
                    ->searchable(),

                Tables\Columns\TextColumn::make('description')
                    ->label('Description')
                    ->limit(60)
                    ->tooltip(fn ($record) => $record->description)
                    ->wrap(),

                Tables\Columns\TextColumn::make('ip_address')
                    ->label('IP')
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->copyable(),

                Tables\Columns\TextColumn::make('performed_by_user_id')
                    ->label('Par')
                    ->formatStateUsing(fn ($state) => $state ? "Admin #{$state}" : 'Système (CLI)')
                    ->badge()
                    ->color(fn ($state) => $state ? 'primary' : 'gray')
                    ->toggleable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('tenant_id')
                    ->label('Établissement')
                    ->options(Tenant::pluck('name', 'id')->toArray())
                    ->searchable(),

                Tables\Filters\SelectFilter::make('action')
                    ->label('Action')
                    ->options(
                        TenantActivityLog::select('action')
                            ->distinct()
                            ->pluck('action', 'action')
                            ->toArray()
                    )
                    ->searchable(),

                Tables\Filters\Filter::make('today')
                    ->label("Aujourd'hui")
                    ->query(fn (Builder $query) => $query->whereDate('performed_at', today())),

                Tables\Filters\Filter::make('this_week')
                    ->label('Cette semaine')
                    ->query(fn (Builder $query) => $query->where('performed_at', '>=', now()->startOfWeek())),
            ])
            ->actions([
                Tables\Actions\Action::make('view_details')
                    ->label('Détails')
                    ->icon('heroicon-o-eye')
                    ->modalHeading(fn ($record) => "Détails — {$record->action}")
                    ->modalContent(fn ($record) => view('filament.modals.activity-log-details', ['record' => $record]))
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Fermer')
                    ->visible(fn ($record) => !empty($record->metadata)),
            ])
            ->defaultSort('performed_at', 'desc')
            ->paginated([25, 50, 100])
            ->emptyStateHeading('Aucune activité enregistrée')
            ->emptyStateDescription('Les actions effectuées sur les établissements apparaîtront ici.')
            ->emptyStateIcon('heroicon-o-clipboard-document-list');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListTenantActivityLogs::route('/'),
        ];
    }
}
