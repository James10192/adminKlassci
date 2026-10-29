<?php

namespace App\Services\Group;

use App\Models\Group;
use App\Models\User;
use App\Services\Saas\SaasCapabilities;
use Illuminate\Support\Facades\Gate;

/**
 * Diagnostic UI minimal : aucune élévation de privilèges ni donnée sensible.
 * Le rôle peut être à jour alors que le cache config d'un ancien déploiement
 * manque une capacité. Ne pas contourner le Gate pour masquer ce problème.
 */
final class GroupWorkspacePermissionInfo
{
    public function canManage(Group $group, string $ability): bool
    {
        return ! $group->trashed()
            && $group->status === 'active'
            && Gate::allows($ability);
    }

    public function explanation(Group $group, string $ability): string
    {
        if ($group->trashed() || $group->status !== 'active') {
            return "Ce groupe est suspendu ou archivé. Réactivez-le avant de modifier ses accès.";
        }

        if (Gate::allows($ability)) {
            return '';
        }

        $user = auth()->user();

        if (! $user instanceof User || ! $user->is_active) {
            return 'Votre session ne dispose pas des droits nécessaires. Reconnectez-vous avec un compte habilité.';
        }

        $rolePermissions = config('saas.capabilities_by_role.'.$user->role, []);
        $declared = is_array($rolePermissions) && in_array($ability, $rolePermissions, true);

        if ($declared) {
            return "La permission est configurée mais n'est pas effective pour cette session. Vérifiez l'état du compte et l'autorisation serveur auprès de l'administrateur.";
        }

        // Signale une configuration potentiellement périmée, sans accorder
        // de privilèges et sans présumer que l'utilisateur doit les posséder.
        return "Ce compte n'a pas la permission « {$ability} » dans la configuration active du serveur. "
            ."Si ce compte devrait l'avoir, vérifiez le déploiement et le cache Laravel "
            ."avec « php artisan saas:permissions:diagnose », puis actualisez le cache de configuration si nécessaire.";
    }
}
