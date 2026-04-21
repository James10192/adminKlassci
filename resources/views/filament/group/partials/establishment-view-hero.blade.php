@php use App\Support\FcfaFormatter; use App\Support\RateHealth; @endphp

@php
    /** @var \App\Models\Tenant $tenant */
    /** @var array<string,mixed> $kpis */
    $students = (int) ($kpis['students'] ?? $kpis['inscriptions'] ?? 0);
    $staff = (int) ($kpis['staff'] ?? 0);
    $rate = (float) ($kpis['collection_rate'] ?? 0);
    $academicYear = $kpis['academic_year'] ?? 'N/A';

    $statusLabel = match ($tenant->status ?? '') {
        'active' => 'Actif',
        'suspended' => 'Suspendu',
        'maintenance' => 'Maintenance',
        default => ucfirst((string) ($tenant->status ?? 'inconnu')),
    };
    $statusTone = match ($tenant->status ?? '') {
        'active' => 'success',
        'suspended' => 'warning',
        'maintenance' => 'warning',
        default => 'danger',
    };
@endphp

<x-group-hero
    :title="$tenant->name"
    :subtitle="($tenant->code ?? '') . ' · Plan ' . ucfirst((string) ($tenant->plan ?? 'n/a'))"
    icon-path="M3.75 21h16.5M4.5 3h15M5.25 3v18m13.5-18v18M9 6.75h1.5m-1.5 3h1.5m-1.5 3h1.5m3-6H15m-1.5 3H15m-1.5 3H15M9 21v-3.375c0-.621.504-1.125 1.125-1.125h3.75c.621 0 1.125.504 1.125 1.125V21"
>
    <x-slot:badges>
        <span class="gp-hero-chip">{{ $statusLabel }}</span>
        @if(! empty($tenant->subdomain))
            <span class="gp-hero-chip">{{ $tenant->subdomain }}.klassci.com</span>
        @endif
    </x-slot:badges>

    <x-slot:actions>
        @if(($tenant->status ?? '') === 'active' && ! empty($tenant->subdomain))
            <a href="https://{{ $tenant->subdomain }}.klassci.com"
               target="_blank"
               rel="noopener"
               class="gp-hero-action">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M13.5 6H5.25A2.25 2.25 0 003 8.25v10.5A2.25 2.25 0 005.25 21h10.5A2.25 2.25 0 0018 18.75V10.5m-10.5 6L21 3m0 0h-5.25M21 3v5.25"/>
                </svg>
                Ouvrir l'établissement
            </a>
        @endif
    </x-slot:actions>

    <x-slot:kpis>
        <div class="gp-hero-kpi">
            <span class="gp-hero-kpi-label">Étudiants inscrits</span>
            <span class="gp-hero-kpi-value">{{ FcfaFormatter::integer($students) }}</span>
            <span class="gp-hero-kpi-meta">année en cours</span>
        </div>

        <div class="gp-hero-kpi">
            <span class="gp-hero-kpi-label">Personnel</span>
            <span class="gp-hero-kpi-value">{{ FcfaFormatter::integer($staff) }}</span>
            <span class="gp-hero-kpi-meta">membres actifs</span>
        </div>

        <div class="gp-hero-kpi" data-tone="{{ RateHealth::tone($rate) }}">
            <span class="gp-hero-kpi-label">Recouvrement</span>
            <span class="gp-hero-kpi-value">{{ number_format($rate, 1, ',', ' ') }}&nbsp;%</span>
            <span class="gp-hero-kpi-meta">{{ RateHealth::label($rate) }}</span>
        </div>

        <div class="gp-hero-kpi">
            <span class="gp-hero-kpi-label">Année universitaire</span>
            <span class="gp-hero-kpi-value" style="font-size: 1.15rem;">{{ $academicYear }}</span>
            <span class="gp-hero-kpi-meta">synchro {{ $statusTone === 'success' ? 'temps réel' : 'différée' }}</span>
        </div>
    </x-slot:kpis>
</x-group-hero>
