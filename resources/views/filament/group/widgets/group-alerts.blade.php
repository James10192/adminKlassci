<x-filament-widgets::widget>
    <div class="gp-alerts-panel">
        <div class="gp-alerts-header">
            <div class="gp-alerts-title-block">
                <div class="gp-alerts-icon">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" style="width:22px;height:22px;">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.007v.008H12v-.008Z" />
                    </svg>
                </div>
                <div>
                    <h3 class="gp-alerts-title">Alertes &amp; actions requises</h3>
                    <p class="gp-alerts-subtitle">
                        @if($totalAlerts === 0)
                            Aucune alerte active — votre groupe est en bonne santé
                        @else
                            {{ $totalAlerts }} {{ Str::plural('alerte', $totalAlerts) }} détectée{{ $totalAlerts > 1 ? 's' : '' }}, {{ min(5, $totalAlerts) }} affichée{{ min(5, $totalAlerts) > 1 ? 's' : '' }} ci-dessous
                        @endif
                    </p>
                </div>
            </div>

            <div class="gp-alerts-summary">
                @if($quotaExceededCount + $quotaCriticalCount > 0)
                    <span class="gp-alerts-chip gp-alerts-chip--critical">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" style="width:14px;height:14px;"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 13.5l10.5-11.25L12 10.5h8.25L9.75 21.75 12 13.5H3.75z" /></svg>
                        {{ $quotaExceededCount + $quotaCriticalCount }} quota{{ $quotaExceededCount + $quotaCriticalCount > 1 ? 's' : '' }}
                    </span>
                @endif
                @if($subscriptionExpiringCount + $subscriptionExpiredCount > 0)
                    <span class="gp-alerts-chip gp-alerts-chip--warning">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" style="width:14px;height:14px;"><path stroke-linecap="round" stroke-linejoin="round" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
                        {{ $subscriptionExpiringCount + $subscriptionExpiredCount }} abonnement{{ $subscriptionExpiringCount + $subscriptionExpiredCount > 1 ? 's' : '' }}
                    </span>
                @endif
                @if($activeReliquatsTotal > 0)
                    <span class="gp-alerts-chip gp-alerts-chip--info">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" style="width:14px;height:14px;"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5M12 21a9 9 0 100-18 9 9 0 000 18z" /></svg>
                        {{ number_format($activeReliquatsTotal, 0, ',', ' ') }} F reliquats
                    </span>
                @endif
                @if($attritionRateAvg > 0)
                    <span class="gp-alerts-chip gp-alerts-chip--neutral">
                        Attrition moyenne : {{ $attritionRateAvg }}%
                    </span>
                @endif
            </div>
        </div>

        @if(count($alerts) > 0)
            <div class="gp-alerts-list">
                @foreach($alerts as $alert)
                    <div class="gp-alert-item gp-alert-item--{{ $alert['severity'] }}">
                        <div class="gp-alert-severity-dot"></div>
                        <div class="gp-alert-content">
                            <div class="gp-alert-message">{{ $alert['message'] }}</div>
                            <div class="gp-alert-tenant">
                                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" style="width:13px;height:13px;"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 21h19.5m-18-18v18m10.5-18v18m6-13.5V21M6.75 6.75h.75m-.75 3h.75m-.75 3h.75m3-6h.75m-.75 3h.75m-.75 3h.75M6.75 21v-3.375c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125V21M3 3h12m-.75 4.5H21m-3.75 3h.008v.008h-.008v-.008zm0 3h.008v.008h-.008v-.008zm0 3h.008v.008h-.008v-.008z" /></svg>
                                {{ $alert['tenant_name'] }}
                            </div>
                        </div>
                        <div class="gp-alert-type">{{ $alert['type'] }}</div>
                    </div>
                @endforeach
            </div>
        @else
            <div class="gp-alerts-empty">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" style="width:40px;height:40px;">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                </svg>
                <p>Aucune alerte pour le moment. Tous les indicateurs sont dans le vert.</p>
            </div>
        @endif

        @if($totalAlerts > count($alerts))
            <div class="gp-alerts-footer">
                <a href="{{ \App\Filament\Group\Pages\AlertsIndex::getUrl() }}" class="gp-alerts-footer-link">
                    Voir les {{ $totalAlerts }} alertes
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" style="width:14px;height:14px;"><path stroke-linecap="round" stroke-linejoin="round" d="M13.5 4.5 21 12m0 0-7.5 7.5M21 12H3" /></svg>
                </a>
            </div>
        @endif
    </div>
</x-filament-widgets::widget>
