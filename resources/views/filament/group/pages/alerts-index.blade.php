@php
    use App\Enums\AlertType;

    $alerts = $this->getAlerts();
    $stats = $this->getStats();

    $typeLabels = [
        AlertType::QuotaExceeded->value => 'Quota dépassé',
        AlertType::QuotaCritical->value => 'Quota critique',
        AlertType::SubscriptionExpired->value => 'Abonnement expiré',
        AlertType::SubscriptionExpiring->value => 'Abonnement expirant',
        AlertType::HighAttrition->value => 'Attrition élevée',
        AlertType::ActiveReliquats->value => 'Reliquats actifs',
        AlertType::PlanMismatch->value => 'Plan dépassé',
        AlertType::StaleTenant->value => 'Tenant inactif',
        AlertType::SslExpiring->value => 'SSL expirant',
        AlertType::EnrollmentDecline->value => 'Inscriptions en baisse',
        AlertType::UnpaidInvoices->value => 'Factures impayées',
        AlertType::TeacherOverload->value => 'Surcharge enseignante',
    ];
@endphp

<x-filament-panels::page>
    <x-group-hero
        title="Toutes les alertes"
        subtitle="Vue consolidée des {{ $stats['total_all'] }} alerte{{ $stats['total_all'] > 1 ? 's' : '' }} détectée{{ $stats['total_all'] > 1 ? 's' : '' }} cross-établissements"
        icon-path="M14.857 17.082a23.848 23.848 0 0 0 5.454-1.31A8.967 8.967 0 0 1 18 9.75V9A6 6 0 0 0 6 9v.75a8.967 8.967 0 0 1-2.312 6.022c1.733.64 3.56 1.085 5.455 1.31m5.714 0a24.255 24.255 0 0 1-5.714 0m5.714 0a3 3 0 1 1-5.714 0"
    >
        <x-slot:badges>
            <span class="gp-hero-chip">Polling 5 min</span>
            <span class="gp-hero-chip">{{ $stats['tenants_affected'] }} établissement{{ $stats['tenants_affected'] > 1 ? 's' : '' }} concerné{{ $stats['tenants_affected'] > 1 ? 's' : '' }}</span>
        </x-slot:badges>

        <x-slot:kpis>
            <div class="gp-hero-kpi" data-tone="{{ $stats['critical'] > 0 ? 'danger' : 'neutral' }}">
                <span class="gp-hero-kpi-label">Critiques</span>
                <span class="gp-hero-kpi-value">{{ $stats['critical'] }}</span>
                <span class="gp-hero-kpi-meta">action immédiate</span>
            </div>
            <div class="gp-hero-kpi" data-tone="{{ $stats['warning'] > 0 ? 'warning' : 'neutral' }}">
                <span class="gp-hero-kpi-label">Avertissements</span>
                <span class="gp-hero-kpi-value">{{ $stats['warning'] }}</span>
                <span class="gp-hero-kpi-meta">à surveiller</span>
            </div>
            <div class="gp-hero-kpi">
                <span class="gp-hero-kpi-label">Informations</span>
                <span class="gp-hero-kpi-value">{{ $stats['info'] }}</span>
                <span class="gp-hero-kpi-meta">pour info</span>
            </div>
            <div class="gp-hero-kpi">
                <span class="gp-hero-kpi-label">Actives</span>
                <span class="gp-hero-kpi-value">{{ $stats['total_active'] }}</span>
                <span class="gp-hero-kpi-meta">non acquittées</span>
            </div>
        </x-slot:kpis>
    </x-group-hero>

    <div
        x-data="{
            severity: 'all',
            type: 'all',
            tenant: '',
            showAcknowledged: false,
            matches(alert) {
                if (this.severity !== 'all' && alert.severity !== this.severity) return false;
                if (this.type !== 'all' && alert.type !== this.type) return false;
                if (!this.showAcknowledged && alert.acknowledged) return false;
                if (this.tenant.trim() !== '') {
                    const needle = this.tenant.trim().toLowerCase();
                    if (!alert.tenant_name.toLowerCase().includes(needle)
                        && !alert.tenant_code.toLowerCase().includes(needle)) return false;
                }
                return true;
            }
        }"
        class="ga-panel"
    >
        <div class="ga-filters">
            <div class="ga-filter-group">
                <span class="ga-filter-label">Sévérité</span>
                <div class="ga-chip-row">
                    <button type="button" class="ga-chip" :class="{ 'ga-chip--active': severity === 'all' }" @click="severity = 'all'">Toutes</button>
                    <button type="button" class="ga-chip ga-chip--critical" :class="{ 'ga-chip--active': severity === 'critical' }" @click="severity = 'critical'">Critiques</button>
                    <button type="button" class="ga-chip ga-chip--warning" :class="{ 'ga-chip--active': severity === 'warning' }" @click="severity = 'warning'">Avertissements</button>
                    <button type="button" class="ga-chip ga-chip--info" :class="{ 'ga-chip--active': severity === 'info' }" @click="severity = 'info'">Info</button>
                </div>
            </div>

            <div class="ga-filter-group">
                <label class="ga-filter-label" for="ga-type">Type</label>
                <select id="ga-type" class="ga-select" x-model="type">
                    <option value="all">Tous les types</option>
                    @foreach($typeLabels as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </select>
            </div>

            <div class="ga-filter-group">
                <label class="ga-filter-label" for="ga-tenant">Établissement</label>
                <input id="ga-tenant" type="search" class="ga-input" placeholder="Chercher par nom ou code…" x-model="tenant">
            </div>

            <div class="ga-filter-group ga-filter-group--toggle">
                <label class="ga-toggle">
                    <input type="checkbox" x-model="showAcknowledged">
                    <span>Afficher acquittées</span>
                </label>
            </div>
        </div>

        @if(count($alerts) === 0)
            <div class="gp-alerts-empty">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" style="width:40px;height:40px;">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                </svg>
                <p>Aucune alerte détectée. Tous les indicateurs sont dans le vert.</p>
            </div>
        @else
            <div class="ga-list">
                @foreach($alerts as $alert)
                    <div
                        class="gp-alert-item gp-alert-item--{{ $alert['severity'] }} ga-alert"
                        x-show="matches({
                            severity: @js($alert['severity']),
                            type: @js($alert['type']),
                            tenant_name: @js($alert['tenant_name']),
                            tenant_code: @js($alert['tenant_code']),
                            acknowledged: @js($alert['acknowledged'])
                        })"
                        @if($alert['acknowledged']) data-acknowledged="true" @endif
                    >
                        <div class="gp-alert-severity-dot"></div>
                        <div class="gp-alert-content">
                            <div class="gp-alert-message">{{ $alert['message'] }}</div>
                            <div class="gp-alert-tenant">
                                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" style="width:13px;height:13px;"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 21h19.5m-18-18v18m10.5-18v18m6-13.5V21M6.75 6.75h.75m-.75 3h.75m-.75 3h.75m3-6h.75m-.75 3h.75m-.75 3h.75M6.75 21v-3.375c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125V21M3 3h12m-.75 4.5H21m-3.75 3h.008v.008h-.008v-.008zm0 3h.008v.008h-.008v-.008zm0 3h.008v.008h-.008v-.008z" /></svg>
                                {{ $alert['tenant_name'] }} <span class="ga-alert-type-tag">{{ $typeLabels[$alert['type']] ?? $alert['type'] }}</span>
                            </div>
                        </div>
                        <div class="ga-alert-actions">
                            @if($alert['acknowledged'])
                                <form method="POST" action="{{ route('groupe.alerts.unacknowledge') }}" class="ga-alert-action-form">
                                    @csrf
                                    <input type="hidden" name="fingerprint" value="{{ $alert['fingerprint'] }}">
                                    <button type="submit" class="ga-btn ga-btn--ghost" title="Rétablir cette alerte">Rétablir</button>
                                </form>
                            @else
                                <form method="POST" action="{{ route('groupe.alerts.acknowledge') }}" class="ga-alert-action-form">
                                    @csrf
                                    <input type="hidden" name="fingerprint" value="{{ $alert['fingerprint'] }}">
                                    <button type="submit" class="ga-btn ga-btn--ghost" title="Masquer pendant 4 heures">Acquitter</button>
                                </form>
                            @endif
                        </div>
                    </div>
                @endforeach
            </div>
        @endif
    </div>
</x-filament-panels::page>
