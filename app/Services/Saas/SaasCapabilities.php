<?php

namespace App\Services\Saas;

use App\Models\User;

final class SaasCapabilities
{
    public const VIEW = 'saas.reconciliation.view';
    public const PROPOSE = 'saas.reconciliation.propose';
    public const REVIEW = 'saas.reconciliation.review';
    public const MANAGE_CATALOG = 'saas.catalog.manage';
    public const MANAGE_GROUP_MEMBERSHIP = 'saas.groups.membership.manage';

    public static function allows(?User $user, string $ability): bool
    {
        if ($user === null || ! $user->is_active) {
            return false;
        }

        return in_array(
            $ability,
            config('saas.capabilities_by_role.'.$user->role, []),
            true
        );
    }
}
