<?php

namespace App\Filament\Resources;

use App\Filament\Resources\TenantBackupResource\Pages;
use App\Models\TenantBackup;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class TenantBackupResource extends Resource
{
    protected static ?string $model = TenantBackup::class;
    protected static ?string $navigationIcon = 'heroicon-o-circle-stack';
    protected static ?string $navigationLabel = 'Backups';
    protected static ?string $modelLabel = 'Backup';
    protected static ?string $pluralModelLabel = 'Backups';
    protected static ?string $navigationGroup = 'Monitoring';
    protected static ?int $navigationSort = 2;

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Informations Backup')->schema([
                Forms\Components\Select::make('tenant_id')->label('Établissement')->relationship('tenant', 'name')->required()->searchable()->preload(),
                Forms\Components\Select::make('type')->label('Type')->required()->options(['full' => 'Complet', 'database' => 'Base de données', 'storage' => 'Fichiers'])->default('full'),
                Forms\Components\TextInput::make('backup_path')->label('Chemin backup')->required()->maxLength(500),
                Forms\Components\TextInput::make('size_bytes')->label('Taille (bytes)')->numeric(),
                Forms\Components\Select::make('status')->label('Statut')->required()->options(['pending' => 'En attente', 'in_progress' => 'En cours', 'completed' => 'Terminé', 'failed' => 'Échoué'])->default('pending'),
                Forms\Components\DateTimePicker::make('expires_at')->label('Expire le')->seconds(false),
            ])->columns(2),
            Forms\Components\Section::make('Détails Techniques')->schema([
                Forms\Components\Textarea::make('database_backup_path')->label('Chemin DB backup')->rows(2)->columnSpanFull(),
                Forms\Components\Textarea::make('storage_backup_path')->label('Chemin storage backup')->rows(2)->columnSpanFull(),
                Forms\Components\Textarea::make('error_message')->label('Message d\'erreur')->rows(3)->columnSpanFull(),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            Tables\Columns\TextColumn::make('tenant.name')->label('Établissement')->searchable()->sortable()->weight('bold'),
            Tables\Columns\BadgeColumn::make('type')->label('Type')->colors(['primary' => 'full', 'success' => 'database', 'warning' => 'storage'])->formatStateUsing(fn ($state) => match($state) { 'full' => 'Complet', 'database' => 'BDD', 'storage' => 'Fichiers', default => $state }),
            Tables\Columns\BadgeColumn::make('status')->label('Statut')->colors(['secondary' => 'pending', 'warning' => 'in_progress', 'success' => 'completed', 'danger' => 'failed'])->formatStateUsing(fn ($state) => match($state) { 'pending' => 'En attente', 'in_progress' => 'En cours', 'completed' => 'Terminé', 'failed' => 'Échoué', default => $state }),
            Tables\Columns\TextColumn::make('size_bytes')->label('Taille')->formatStateUsing(fn ($state) => $state ? number_format($state / 1048576, 2) . ' MB' : '-')->sortable(),
            Tables\Columns\TextColumn::make('expires_at')->label('Expire le')->dateTime('d/m/Y')->sortable()->description(fn ($record) => $record->expires_at?->diffForHumans()),
            Tables\Columns\TextColumn::make('created_at')->label('Créé le')->dateTime('d/m/Y H:i')->sortable(),
        ])->filters([
            Tables\Filters\SelectFilter::make('tenant_id')->label('Établissement')->relationship('tenant', 'name')->searchable()->preload(),
            Tables\Filters\SelectFilter::make('type')->label('Type')->options(['full' => 'Complet', 'database' => 'BDD', 'storage' => 'Fichiers']),
            Tables\Filters\SelectFilter::make('status')->label('Statut')->options(['pending' => 'En attente', 'in_progress' => 'En cours', 'completed' => 'Terminé', 'failed' => 'Échoué']),
            Tables\Filters\TrashedFilter::make(),
        ])->actions([
            Tables\Actions\ViewAction::make(),
            Tables\Actions\EditAction::make(),
        ])->bulkActions([
            Tables\Actions\BulkActionGroup::make([
                Tables\Actions\DeleteBulkAction::make(),
                Tables\Actions\ForceDeleteBulkAction::make(),
                Tables\Actions\RestoreBulkAction::make(),
            ]),
        ])->defaultSort('created_at', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListTenantBackups::route('/'),
            'create' => Pages\CreateTenantBackup::route('/create'),
            'view' => Pages\ViewTenantBackup::route('/{record}'),
            'edit' => Pages\EditTenantBackup::route('/{record}/edit'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->withoutGlobalScopes([SoftDeletingScope::class]);
    }
}
