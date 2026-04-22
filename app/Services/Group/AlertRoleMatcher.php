<?php

namespace App\Services\Group;

use App\Enums\AlertType;

/**
 * Decides whether a given `GroupMember` role should receive a specific
 * `AlertType`. Strategy pattern — the matrix is data, not conditionals
 * scattered through the dispatcher.
 *
 * Defaults: `fondateur` and `directeur_general` get everything (full
 * operational oversight). `directeur_financier` gets only alerts that
 * directly affect cash — subscription expiry, unpaid invoices, reliquats,
 * quota issues that block billing. SSL / stale tenant / teacher workload
 * are ops signals, noise for a CFO.
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
        return match ($role) {
            // DGA shares the founder/DG operational oversight scope — they
            // act as the DG's proxy and must see every alert the DG would.
            'fondateur', 'directeur_general', 'directeur_general_adjoint' => true,
            'directeur_financier' => in_array($type, self::FINANCIAL_ROLE_ALERTS, true),
            default => false,
        };
    }
}
