<?php

namespace App\Services\Group;

use App\Enums\AlertType;
use App\Enums\GroupMemberRole;

/**
 * Decides whether a given `GroupMember` role should receive a specific
 * `AlertType`. Strategy pattern — the matrix is data, not conditionals
 * scattered through the dispatcher.
 *
 * Defaults: operational-oversight roles (fondateur, directeur_general,
 * directeur_general_adjoint) get everything. `directeur_financier` gets
 * only alerts that directly affect cash — subscription expiry, unpaid
 * invoices, reliquats, quota issues that block billing. SSL / stale tenant
 * / teacher workload are ops signals, noise for a CFO.
 */
class AlertRoleMatcher
{
    private const FINANCIAL_ROLE_ALERTS = [
        AlertType::SubscriptionExpired,
        AlertType::SubscriptionExpiring,
        AlertType::QuotaExceeded,
        AlertType::QuotaCritical,
        AlertType::PlanMismatch,
        AlertType::ActiveReliquats,
        AlertType::UnpaidInvoices,
    ];

    public function isSubscribed(string $role, AlertType $type): bool
    {
        $enum = GroupMemberRole::tryFrom($role);

        if ($enum === null) {
            return false;
        }

        if ($enum->hasOperationalOversight()) {
            return true;
        }

        return $enum === GroupMemberRole::DirecteurFinancier
            && in_array($type, self::FINANCIAL_ROLE_ALERTS, true);
    }
}
