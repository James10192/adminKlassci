<?php

namespace App\Filament\Resources\GroupResource\Actions;

use App\Filament\Forms\Group\AssociateTenantFields;
use App\Filament\Forms\Group\GroupMemberFields;
use App\Models\Group;
use App\Services\Group\GroupMemberCreator;
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
            ->color('primary')
            ->disabled(fn (): bool => ! self::mayManage($group, SaasCapabilities::MANAGE_GROUP_MEMBERSHIP))
            ->tooltip(fn (): ?string => self::unavailableReason($group, SaasCapabilities::MANAGE_GROUP_MEMBERSHIP))
            ->modalHeading('Associer un établissement à '.$group->name)
            ->modalDescription("Recherchez une instance existante et sans groupe. L'opération change sa visibilité dans le portail Groupe et sera journalisée, sans modifier son abonnement.")
            ->modalSubmitActionLabel('Associer au groupe')
            ->modalWidth('lg')
            ->form(AssociateTenantFields::make())
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
            ->color('gray')
            ->disabled(fn (): bool => ! self::mayManage($group, SaasCapabilities::MANAGE_GROUP_MEMBERS))
            ->tooltip(fn (): ?string => self::unavailableReason($group, SaasCapabilities::MANAGE_GROUP_MEMBERS))
            ->modalHeading('Inviter un membre de '.$group->name)
            ->modalDescription('Renseignez les informations du membre et son profil. La procédure d’accès dépend de la configuration des invitations du portail.')
            ->modalSubmitActionLabel('Ajouter le membre')
            ->modalWidth('lg')
            ->form(GroupMemberFields::make(quickCreate: true))
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
        return ! $group->trashed() && $group->status === 'active' && Gate::allows($ability);
    }

    private static function unavailableReason(Group $group, string $ability): ?string
    {
        if ($group->trashed() || $group->status !== 'active') {
            return "Le groupe est suspendu ou archivé. Réactivez-le avant d'ajouter des accès.";
        }

        return Gate::allows($ability)
            ? null
            : 'Votre compte ne dispose pas de la permission nécessaire. Contactez un administrateur habilité.';
    }
}
