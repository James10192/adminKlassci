<?php

namespace App\Filament\Resources\GroupResource\RelationManagers;

use App\Models\Tenant;
use App\Filament\Forms\Group\AssociateTenantFields;
use Livewire\Attributes\On;
use App\Services\Group\GroupTenantMembershipService;
use App\Services\Saas\SaasCapabilities;
use Filament\Forms;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Gate;

/**
 * Group::tenants() est HasMany (FK tenants.group_id).
 * Attach/Detach utilisent un pivot BelongsToMany et produisaient une 500.
 * L'association est effectuée dans le service de domaine et auditée.
 */
class TenantsRelationManager extends RelationManager
{
    protected static string $relationship = 'tenants';
    protected static ?string $title = 'Établissements du groupe';
    protected static ?string $recordTitleAttribute = 'name';

    public function isReadOnly(): bool
    {
        // Ne jamais rendre toute la relation read-only selon le Gate :
        // Filament masque alors même les actions explicitement autorisées.
        // Les boutons sont contrôlés individuellement et le service revérifie la capacité.
        return false;
    }

    #[On('group-workspace-updated')]
    public function refreshWorkspace(): void
    {
        // Livewire déclenche un rerender et Filament réinterroge la table.
    }

    public function table(Table $table): Table
    {
        return $table
            ->emptyStateHeading('Aucun établissement rattaché')
            ->emptyStateDescription('Utilisez « Ajouter un établissement » pour rechercher une instance existante sans groupe.')
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label('Établissement')->searchable()->weight('bold'),
                Tables\Columns\TextColumn::make('code')
                    ->label('Code')->badge()->color('gray'),
                Tables\Columns\TextColumn::make('status')
                    ->label('Statut')->badge()->color(fn (?string $state): string => match ($state) {
                        'active' => 'success',
                        'suspended' => 'warning',
                        default => 'gray',
                    }),
                Tables\Columns\TextColumn::make('plan')
                    ->label('Plan')->badge()
                    ->formatStateUsing(fn (?string $state): string => $state ? ucfirst($state) : '—'),
                Tables\Columns\TextColumn::make('subdomain')
                    ->label('URL')
                    ->formatStateUsing(fn ($state, Tenant $record) => $record->hote)
                    ->url(fn (Tenant $record) => $record->full_url, shouldOpenInNewTab: true)
                    ->color('primary'),
            ])
            ->headerActions([
                Tables\Actions\Action::make('associate_tenant')
                    ->label('Ajouter un établissement')
                    ->icon('heroicon-o-plus-circle')
                    ->color('primary')
                    ->visible(fn (): bool => Gate::allows(SaasCapabilities::MANAGE_GROUP_MEMBERSHIP) && $this->getOwnerRecord()->status === 'active')
                    ->modalHeading('Associer un établissement existant au groupe')
                    ->modalDescription('Seuls les établissements sans groupe sont proposés. Leur accès dans le portail Groupe peut changer ; aucun contrat, forfait ou produit SaaS ne sera modifié.')
                    ->modalSubmitActionLabel('Confirmer le rattachement')
                    ->modalWidth('lg')
                    ->form(AssociateTenantFields::make())
                    ->action(function (array $data): void {
                        Gate::authorize(SaasCapabilities::MANAGE_GROUP_MEMBERSHIP);
                        app(GroupTenantMembershipService::class)->associate(
                            auth()->user(),
                            (int) $this->getOwnerRecord()->getKey(),
                            (int) $data['tenant_id'],
                            (string) $data['reason'],
                        );

                        \Filament\Notifications\Notification::make()
                            ->title('Établissement associé au groupe')
                            ->body('Le rattachement est enregistré et tracé. Les abonnements restent inchangés.')
                            ->success()->send();
                    }),
            ])
            ->actions([
                Tables\Actions\Action::make('dissociate_tenant')
                    ->label('Retirer du groupe')
                    ->icon('heroicon-o-link-slash')
                    ->color('warning')
                    ->visible(fn (): bool => Gate::allows(SaasCapabilities::MANAGE_GROUP_MEMBERSHIP))
                    ->requiresConfirmation()
                    ->modalHeading('Retirer cet établissement du groupe ?')
                    ->modalDescription('L’établissement ne sera pas supprimé. Il ne sera plus visible par les membres du groupe dans leur portail ; aucun abonnement n’est résilié.')
                    ->modalSubmitActionLabel('Confirmer le retrait')
                    ->form([
                        Forms\Components\Textarea::make('reason')
                            ->label('Motif du retrait')->required()
                            ->minLength(10)->maxLength(500)->rows(3),
                    ])
                    ->action(function (Tenant $record, array $data): void {
                        Gate::authorize(SaasCapabilities::MANAGE_GROUP_MEMBERSHIP);
                        app(GroupTenantMembershipService::class)->dissociate(
                            auth()->user(),
                            (int) $this->getOwnerRecord()->getKey(),
                            (int) $record->getKey(),
                            (string) $data['reason'],
                        );

                        \Filament\Notifications\Notification::make()
                            ->title('Établissement retiré du groupe')
                            ->body('La modification et son motif ont été enregistrés.')
                            ->success()->send();
                    }),
            ])
            ->bulkActions([]); // Aucun déplacement de masse d'accès au portail groupe.
    }
}
