@php
    /**
     * @var array{
     *     group_name: string,
     *     user_name: string,
     *     role: string,
     *     establishment_count: int,
     *     academic_years: list<string>,
     *     last_sync: string,
     *     kpis: array<string,mixed>,
     * } $context
     */
    $k = $context['kpis'];
    $totalStudents = (int) ($k['total_students'] ?? 0);
    $collectionRate = (float) ($k['collection_rate'] ?? 0);
    $revenueCollected = (float) ($k['total_revenue_collected'] ?? 0);
    $establishmentCount = $context['establishment_count'];

    $formatNumber = fn (float $n): string => number_format($n, 0, ',', ' ');
    $formatFcfa = fn (float $n): string => $n >= 1_000_000
        ? number_format($n / 1_000_000, 1, ',', ' ') . ' M'
        : number_format($n / 1_000, 0, ',', ' ') . ' k';

    $recoveryColor = $collectionRate >= 70 ? 'success' : ($collectionRate >= 50 ? 'warning' : 'danger');
@endphp

<x-group-hero
    :title="$context['group_name']"
    :subtitle="'Bienvenue, ' . $context['user_name'] . ' — ' . $context['role']"
    icon-path="M2.25 21h19.5m-18-18v18m10.5-18v18m6-13.5V21M6.75 6.75h.75m-.75 3h.75m-.75 3h.75m3-6h.75m-.75 3h.75m-.75 3h.75M6.75 21v-3.375c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125V21M3 3h12m-.75 4.5H21m-3.75 3h.008v.008h-.008v-.008zm0 3h.008v.008h-.008v-.008zm0 3h.008v.008h-.008v-.008z"
>
    <x-slot:badges>
        <span class="gp-hero-chip">
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
            Synchro&nbsp;: {{ $context['last_sync'] }}
        </span>
        @if(! empty($context['academic_years']))
            <span class="gp-hero-chip">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 012.25-2.25h13.5A2.25 2.25 0 0121 7.5v11.25m-18 0A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75m-18 0v-7.5A2.25 2.25 0 015.25 9h13.5A2.25 2.25 0 0121 11.25v7.5" /></svg>
                {{ implode(' / ', $context['academic_years']) }}
            </span>
        @endif
        <span class="gp-hero-badge">
            <span class="gp-hero-badge-dot" aria-hidden="true"></span>
            Portail Groupe
        </span>
    </x-slot:badges>

    <x-slot:kpis>
        <div class="gp-hero-kpi">
            <span class="gp-hero-kpi-label">Étudiants inscrits</span>
            <span class="gp-hero-kpi-value">{{ $formatNumber($totalStudents) }}</span>
            <span class="gp-hero-kpi-meta">{{ $establishmentCount }} {{ \Illuminate\Support\Str::plural('établissement', $establishmentCount) }}</span>
        </div>

        <div class="gp-hero-kpi" data-tone="{{ $recoveryColor }}">
            <span class="gp-hero-kpi-label">Recouvrement</span>
            <span class="gp-hero-kpi-value">{{ number_format($collectionRate, 1, ',', ' ') }}&nbsp;%</span>
            <span class="gp-hero-kpi-meta">{{ $formatFcfa($revenueCollected) }} FCFA encaissés</span>
        </div>

        <div class="gp-hero-kpi">
            <span class="gp-hero-kpi-label">Personnel</span>
            <span class="gp-hero-kpi-value">{{ $formatNumber((float) ($k['total_staff'] ?? 0)) }}</span>
            <span class="gp-hero-kpi-meta">membres cross-groupe</span>
        </div>

        <div class="gp-hero-kpi">
            <span class="gp-hero-kpi-label">Assiduité</span>
            <span class="gp-hero-kpi-value">{{ number_format((float) ($k['avg_attendance_rate'] ?? 0), 1, ',', ' ') }}&nbsp;%</span>
            <span class="gp-hero-kpi-meta">moyenne pondérée</span>
        </div>
    </x-slot:kpis>
</x-group-hero>
