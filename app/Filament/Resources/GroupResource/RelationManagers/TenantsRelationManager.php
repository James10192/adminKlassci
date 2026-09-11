<?php

namespace App\Filament\Resources\GroupResource\RelationManagers;

use App\Models\Tenant;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * Un établissement appartient à un groupe par sa colonne `tenants.group_id` :
 * la relation est un hasMany, pas une table pivot. Les actions Attach/Detach de
 * Filament ne savent travailler que sur un belongsToMany (elles appellent
 * getQualifiedRelatedKeyName(), qui n'existe pas sur un hasMany) — d'où les
 * actions maison ci-dessous, qui posent et retirent simplement le group_id.
 */
class TenantsRelationManager extends RelationManager
{
    protected static string $relationship = 'tenants';

    protected static ?string $title = 'Établissements du groupe';

    protected static ?string $modelLabel = 'établissement';

    protected static ?string $pluralModelLabel = 'établissements';

    protected static ?string $recordTitleAttribute = 'name';

    public function isReadOnly(): bool
    {
        return false;
    }

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
                    ->formatStateUsing(fn (string $state, $record) => $record->hote)
                    ->url(fn ($record) => $record->full_url, shouldOpenInNewTab: true)
                    ->color('primary'),
            ])
            ->headerActions([
                Tables\Actions\Action::make('attach')
                    ->label('Ajouter un établissement')
                    ->icon('heroicon-o-plus')
                    ->modalHeading('Ajouter un établissement au groupe')
                    ->modalSubmitActionLabel('Ajouter')
                    ->form([
                        Forms\Components\Select::make('tenant_id')
                            ->label('Établissement')
                            ->options(fn (): array => self::etablissementsLibres())
                            ->searchable()
                            ->required()
                            ->helperText('Seuls les établissements qui n\'appartiennent encore à aucun groupe sont proposés. Pour en déplacer un, retirez-le d\'abord de son groupe actuel.'),
                    ])
                    ->action(function (array $data): void {
                        $etablissement = Tenant::whereKey($data['tenant_id'])
                            ->whereNull('group_id')
                            ->first();

                        if (! $etablissement) {
                            Notification::make()
                                ->danger()
                                ->title('Établissement indisponible')
                                ->body('Il a été rattaché à un autre groupe entre-temps. Rechargez la page.')
                                ->send();

                            return;
                        }

                        $etablissement->update(['group_id' => $this->getOwnerRecord()->getKey()]);

                        Notification::make()
                            ->success()
                            ->title('Établissement ajouté')
                            ->body("{$etablissement->name} fait maintenant partie du groupe.")
                            ->send();
                    }),
            ])
            ->actions([
                Tables\Actions\Action::make('detach')
                    ->label('Retirer')
                    ->icon('heroicon-o-x-mark')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalHeading('Retirer l\'établissement du groupe')
                    ->modalDescription('L\'établissement ne sera pas supprimé, il sera simplement détaché du groupe. Le fondateur ne le verra plus dans son portail.')
                    ->modalSubmitActionLabel('Retirer')
                    ->action(function (Tenant $record): void {
                        $record->update(['group_id' => null]);

                        Notification::make()
                            ->success()
                            ->title('Établissement retiré')
                            ->body("{$record->name} n'appartient plus à ce groupe.")
                            ->send();
                    }),
            ]);
    }

    /**
     * @return array<int, string>
     */
    private static function etablissementsLibres(): array
    {
        return Tenant::query()
            ->whereNull('group_id')
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }
}
