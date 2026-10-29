<?php

namespace App\Filament\Resources\GroupResource\Actions;

use App\Filament\Forms\Group\AssociateTenantFields;
use App\Filament\Forms\Group\GroupMemberFields;
use App\Models\Group;
use App\Services\Group\GroupMemberCreator;
use App\Services\Group\GroupWorkspacePermissionInfo;
use App\Services\Group\GroupTenantMembershipService;
use App\Services\Saas\SaasCapabilities;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Gate;
use Livewire\Component;

/**
 * Two contextual, permission-safe modals reusable on EditGroup and ViewGroup.
 * Domain mutations remain in dedicated services; no duplicate form schemas.
 */
final class GroupWorkspaceActions
{
    public static function associateTenant(Group $group): Action
    {
        return Action::make('quickAssociateTenant')
            ->label('Ajouter un établissement')
            ->icon('heroicon-o-building-office-2')
            ->color(fn (): string => self::mayManage($group, SaasCapabilities::MANAGE_GROUP_MEMBERSHIP) ? 'primary' : 'gray')
            // Un bouton bloque ne fournit aucune explication sur mobile/clavier.
            // L'ouvrir affiche le motif SANS formulaire ni bouton de validation.
            ->tooltip(fn (): ?string => self::mayManage($group, SaasCapabilities::MANAGE_GROUP_MEMBERSHIP)
                ? null : 'Consulter pourquoi cette opération est indisponible')
            ->modalHeading(fn (): string => self::mayManage($group, SaasCapabilities::MANAGE_GROUP_MEMBERSHIP)
                ? 'Associer un établissement à '.$group->name : 'Rattachement indisponible')
            ->modalDescription(fn (): string => self::mayManage($group, SaasCapabilities::MANAGE_GROUP_MEMBERSHIP)
                ? "Recherchez une instance existante et sans groupe. L'opération change sa visibilité dans le portail Groupe et sera journalisée, sans modifier son abonnement."
                : app(GroupWorkspacePermissionInfo::class)->explanation($group, SaasCapabilities::MANAGE_GROUP_MEMBERSHIP))
            ->modalSubmitActionLabel('Associer au groupe')
            ->modalWidth('lg')
            ->form(fn (): array => self::mayManage($group, SaasCapabilities::MANAGE_GROUP_MEMBERSHIP)
                ? AssociateTenantFields::make() : [])
            ->modalSubmitAction(fn ($action) => self::mayManage($group, SaasCapabilities::MANAGE_GROUP_MEMBERSHIP) ? $action : false)
            ->action(function (array $data, Component $livewire) use ($group): void {
                Gate::authorize(SaasCapabilities::MANAGE_GROUP_MEMBERSHIP);
                app(GroupTenantMembershipService::class)->associate(
                    auth()->user(), (int) $group->getKey(),
                    (int) $data['tenant_id'], (string) $data['reason'],
                );

                $livewire->dispatch('group-workspace-updated');

                Notification::make()
                    ->title('Établissement ajouté')
                    ->body('Le rattachement est enregistré. Les abonnements restent inchangés.')
                    ->success()->send();
            });
    }

    public static function addMember(Group $group): Action
    {
        return Action::make('quickAddMember')
            ->label('Ajouter un membre')
            ->icon('heroicon-o-user-plus')
            // Un bouton gris n'indique pas s'il est secondaire ou desactive.
            ->color(fn (): string => self::mayManage($group, SaasCapabilities::MANAGE_GROUP_MEMBERS) ? 'primary' : 'gray')
            ->tooltip(fn (): ?string => self::mayManage($group, SaasCapabilities::MANAGE_GROUP_MEMBERS)
                ? null : 'Consulter pourquoi cette opération est indisponible')
            ->modalHeading(fn (): string => self::mayManage($group, SaasCapabilities::MANAGE_GROUP_MEMBERS)
                ? 'Inviter un membre de '.$group->name : 'Ajout de membre indisponible')
            ->modalDescription(fn (): string => self::mayManage($group, SaasCapabilities::MANAGE_GROUP_MEMBERS)
                ? 'Renseignez les informations du membre et son profil. La procédure d’accès dépend de la configuration des invitations du portail.'
                : app(GroupWorkspacePermissionInfo::class)->explanation($group, SaasCapabilities::MANAGE_GROUP_MEMBERS))
            ->modalSubmitActionLabel('Ajouter le membre')
            ->modalWidth('lg')
            ->form(fn (): array => self::mayManage($group, SaasCapabilities::MANAGE_GROUP_MEMBERS)
                ? GroupMemberFields::make(quickCreate: true) : [])
            ->modalSubmitAction(fn ($action) => self::mayManage($group, SaasCapabilities::MANAGE_GROUP_MEMBERS) ? $action : false)
            ->action(function (array $data, Component $livewire) use ($group): void {
                Gate::authorize(SaasCapabilities::MANAGE_GROUP_MEMBERS);
                app(GroupMemberCreator::class)->create(
                    auth()->user(), (int) $group->getKey(), $data,
                );

                $livewire->dispatch('group-workspace-updated');

                Notification::make()
                    ->title('Membre ajouté au groupe')
                    ->body(config('group_portal.invite_flow_enabled', false) && filled($data['email'] ?? null)
                        ? "Une invitation a été préparée pour cet e-mail ; sa livraison n'est pas encore confirmée."
                        : 'Le membre a été créé. Communiquez son accès initial uniquement par un canal vérifié.')
                    ->success()->send();
            });
    }

    private static function mayManage(Group $group, string $ability): bool
    {
        return app(GroupWorkspacePermissionInfo::class)->canManage($group, $ability);
    }
}
