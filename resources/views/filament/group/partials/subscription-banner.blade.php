@php
    use App\Services\Group\SubscriptionTierResolver;
    use App\Services\TenantAggregationService;

    $shouldRender = false;
    $tone = null;
    $worstTier = null;
    $role = 'status';
    $ariaLive = 'polite';
    $isCritical = false;
    $dismissible = false;
    $headline = '';
    $detail = '';
    $establishmentsRoute = '#';

    if (config('group_portal.alerts_banner_enabled', true)) {
        $group = auth('group')->user()?->group;

        if ($group) {
            $health = app(TenantAggregationService::class)->getGroupHealthMetrics($group);
            $worstTier = $health['subscription_worst_tier'] ?? null;

            $dismissible = in_array($worstTier, [
                SubscriptionTierResolver::TIER_INFO,
                SubscriptionTierResolver::TIER_WARNING,
            ], true);

            $dismissedUntil = session('gp_subscription_banner_dismissed_until');
            $isDismissed = $dismissible && $dismissedUntil && now()->lessThan($dismissedUntil);

            $shouldRender = $worstTier !== null && ! $isDismissed;
        }
    }

    if ($shouldRender) {
        $tone = match ($worstTier) {
            SubscriptionTierResolver::TIER_EXPIRED, SubscriptionTierResolver::TIER_URGENT => 'danger',
            SubscriptionTierResolver::TIER_WARNING => 'warning',
            SubscriptionTierResolver::TIER_INFO => 'info',
        };

        $isCritical = in_array($worstTier, [
            SubscriptionTierResolver::TIER_EXPIRED,
            SubscriptionTierResolver::TIER_URGENT,
        ], true);

        $role = $isCritical ? 'alert' : 'status';
        $ariaLive = $isCritical ? 'assertive' : 'polite';

        $totalExpiring = (int) ($health['subscription_expiring_total_count'] ?? 0);
        $worstName = $health['subscription_worst_tenant_name'] ?? '—';
        $days = $health['subscription_worst_days_remaining'];

        $headline = match (true) {
            $worstTier === SubscriptionTierResolver::TIER_EXPIRED
                => "Abonnement expiré — {$worstName}",
            $days === 0
                => "Abonnement expire aujourd'hui — {$worstName}",
            default
                => "Abonnement expire sous {$days} jour" . ($days > 1 ? 's' : '') . " — {$worstName}",
        };

        $others = $totalExpiring - 1;
        $detail = $others > 0
            ? $others . ' autre' . ($others > 1 ? 's' : '') . ' établissement' . ($others > 1 ? 's' : '') . ' à surveiller'
            : 'Un renouvellement garantit la continuité du service pour cet établissement.';

        $establishmentsRoute = route('filament.group.resources.establishments.index');
    }
@endphp

@if ($shouldRender)
<div
    class="gp-alert-banner"
    data-tone="{{ $tone }}"
    data-tier="{{ $worstTier }}"
    role="{{ $role }}"
    aria-live="{{ $ariaLive }}"
>
    <div class="gp-alert-banner-body">
        <div class="gp-alert-banner-icon" aria-hidden="true">
            @if ($isCritical)
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="22" height="22">
                    <path d="M12 9v4"/>
                    <path d="M12 17h.01"/>
                    <path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/>
                </svg>
            @else
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="22" height="22">
                    <circle cx="12" cy="12" r="10"/>
                    <path d="M12 8v4"/>
                    <path d="M12 16h.01"/>
                </svg>
            @endif
        </div>
        <div class="gp-alert-banner-text">
            <p class="gp-alert-banner-title">{{ $headline }}</p>
            <p class="gp-alert-banner-detail">{{ $detail }}</p>
        </div>
        <div class="gp-alert-banner-actions">
            <a href="{{ $establishmentsRoute }}" class="gp-alert-banner-action">
                Voir les établissements
            </a>
            @if ($dismissible)
                <form method="POST" action="{{ route('groupe.subscription-banner.dismiss') }}" class="gp-alert-banner-dismiss-form">
                    @csrf
                    <button type="submit" class="gp-alert-banner-dismiss" aria-label="Masquer l'alerte pendant 4 heures">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="16" height="16" aria-hidden="true">
                            <path d="M18 6L6 18"/>
                            <path d="M6 6l12 12"/>
                        </svg>
                    </button>
                </form>
            @endif
        </div>
    </div>
</div>
@endif
