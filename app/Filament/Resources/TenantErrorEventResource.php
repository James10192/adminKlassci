<?php

namespace App\Filament\Resources;

use App\Filament\Resources\TenantErrorEventResource\Pages;
use App\Models\TenantErrorEvent;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;

/** Surveillance des erreurs corrélées, sans requête SQL ni trace personnelle. */
class TenantErrorEventResource extends Resource
{
    protected static ?string $model = TenantErrorEvent::class;
    protected static ?string $navigationGroup = 'Surveillance';
    protected static ?string $navigationLabel = 'Incidents techniques';
    protected static ?string $modelLabel = 'incident technique';
    protected static ?string $pluralModelLabel = 'incidents techniques';
    protected static ?string $navigationIcon = 'heroicon-o-bug-ant';
    protected static ?int $navigationSort = 2;

    public static function canViewAny(): bool
    {
        return Gate::allows('support.tickets.view');
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

    public static function getNavigationBadge(): ?string
    {
        if (! static::canViewAny()) {
            return null;
        }

        $total = TenantErrorEvent::query()->where('status', 'new')
            ->where('occurred_at', '>=', now()->subDays(7))->count();

        return $total ? (string) $total : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
    }

    public static function form(Form $form): Form
    {
        return $form->schema([]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('occurred_at', 'desc')
            ->poll('30s')
            ->columns([
                Tables\Columns\TextColumn::make('tenant.code')->label('Établissement')
                    ->badge()->searchable()->sortable(),
                Tables\Columns\TextColumn::make('request_id')->label('Référence')
                    ->copyable()->searchable()->fontFamily('mono')->limit(30),
                Tables\Columns\TextColumn::make('category')->label('Catégorie')
                    ->badge()->color('warning'),
                Tables\Columns\TextColumn::make('route_name')->label('Route')
                    ->placeholder('—')->searchable()->wrap(),
                Tables\Columns\TextColumn::make('exception_class')->label('Exception')
                    ->limit(40)->tooltip(fn (TenantErrorEvent $record) => $record->exception_class),
                Tables\Columns\TextColumn::make('sqlstate')->label('SQLSTATE')->placeholder('—'),
                Tables\Columns\TextColumn::make('status')->label('Traitement')
                    ->badge()->color(fn (string $state) => match ($state) {
                        'resolved' => 'success',
                        'acknowledged' => 'info',
                        default => 'danger',
                    }),
                Tables\Columns\TextColumn::make('occurred_at')->label('Survenu')
                    ->dateTime('d/m/Y H:i:s')->sortable()->since(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('tenant_id')->label('Établissement')
                    ->relationship('tenant', 'code')->searchable()->preload(),
                Tables\Filters\SelectFilter::make('category')->label('Catégorie')
                    ->options(['database' => 'Base de données', 'application' => 'Application', 'infrastructure' => 'Infrastructure']),
                Tables\Filters\SelectFilter::make('status')->label('Traitement')
                    ->options(['new' => 'À traiter', 'acknowledged' => 'Pris en charge', 'resolved' => 'Résolu'])
                    ->default('new'),
            ])
            ->actions([
                Tables\Actions\Action::make('acknowledge')
                    ->label('Prendre en charge')->icon('heroicon-o-hand-raised')->color('info')
                    ->visible(fn (TenantErrorEvent $record) => $record->status === 'new' && Gate::allows('support.tickets.manage'))
                    ->action(function (TenantErrorEvent $record): void {
                        abort_unless(Gate::allows('support.tickets.manage'), 403);
                        $record->forceFill(['status' => 'acknowledged'])->save();
                    }),
                Tables\Actions\Action::make('resolve')
                    ->label('Résoudre')->icon('heroicon-o-check-circle')->color('success')
                    ->requiresConfirmation()
                    ->visible(fn (TenantErrorEvent $record) => $record->status !== 'resolved' && Gate::allows('support.tickets.manage'))
                    ->action(function (TenantErrorEvent $record): void {
                        abort_unless(Gate::allows('support.tickets.manage'), 403);
                        $record->forceFill([
                            'status' => 'resolved',
                            'resolved_by' => auth()->id(),
                            'resolved_at' => now(),
                        ])->save();
                    }),
            ])
            ->emptyStateHeading('Aucun incident technique à traiter')
            ->emptyStateDescription('Les erreurs transmises par les instances apparaîtront ici.');
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListTenantErrorEvents::route('/')];
    }
}
