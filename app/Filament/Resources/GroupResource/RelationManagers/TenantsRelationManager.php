<?php

namespace App\Filament\Resources\GroupResource\RelationManagers;

use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

class TenantsRelationManager extends RelationManager
{
    protected static string $relationship = 'tenants';

    protected static ?string $title = 'Établissements du groupe';

    protected static ?string $recordTitleAttribute = 'name';

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label('Nom')
                    ->searchable()
                    ->weight('bold'),

                Tables\Columns\TextColumn::make('code')
                    ->label('Code')
                    ->badge()
                    ->color('gray'),

                Tables\Columns\TextColumn::make('status')
                    ->label('Statut')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'active' => 'success',
                        'suspended' => 'warning',
                        default => 'danger',
                    }),

                Tables\Columns\TextColumn::make('plan')
                    ->label('Plan')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => ucfirst($state)),

                Tables\Columns\TextColumn::make('subdomain')
                    ->label('URL')
                    ->formatStateUsing(fn (string $state) => "{$state}.klassci.com")
                    ->url(fn ($record) => "https://{$record->subdomain}.klassci.com", shouldOpenInNewTab: true)
                    ->color('primary'),
            ])
            ->headerActions([
                Tables\Actions\AttachAction::make()
                    ->label('Ajouter un établissement')
                    ->preloadRecordSelect()
                    ->recordSelectSearchColumns(['name', 'code'])
                    ->modalHeading('Ajouter un établissement au groupe'),
            ])
            ->actions([
                Tables\Actions\DetachAction::make()
                    ->label('Retirer')
                    ->requiresConfirmation()
                    ->modalHeading('Retirer l\'établissement du groupe')
                    ->modalDescription('L\'établissement ne sera pas supprimé, il sera simplement détaché du groupe. Le fondateur ne le verra plus dans son portail.'),
            ]);
    }
}
