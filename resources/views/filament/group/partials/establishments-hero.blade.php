@php use App\Support\FcfaFormatter; use App\Support\RateHealth; @endphp

@php
    /** @var array{total_students:int,total_staff:int,establishment_count:int,avg_rate:float} $context */
    $rate = (float) $context['avg_rate'];
@endphp

<x-group-hero
    title="Mes Établissements"
    subtitle="Pilotage centralisé des établissements du groupe"
    icon-path="M2.25 21h19.5m-18-18v18m10.5-18v18m6-13.5V21M6.75 6.75h.75m-.75 3h.75m-.75 3h.75m3-6h.75m-.75 3h.75m-.75 3h.75M6.75 21v-3.375c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125V21M3 3h12m-.75 4.5H21m-3.75 3h.008v.008h-.008v-.008zm0 3h.008v.008h-.008v-.008zm0 3h.008v.008h-.008v-.008z"
>
    <x-slot:badges>
        <span class="gp-hero-chip">{{ $context['establishment_count'] }} {{ \Illuminate\Support\Str::plural('établissement', $context['establishment_count']) }}</span>
    </x-slot:badges>

    <x-slot:kpis>
        <div class="gp-hero-kpi">
            <span class="gp-hero-kpi-label">Étudiants inscrits</span>
            <span class="gp-hero-kpi-value">{{ FcfaFormatter::integer($context['total_students']) }}</span>
            <span class="gp-hero-kpi-meta">cumul cross-groupe</span>
        </div>

        <div class="gp-hero-kpi">
            <span class="gp-hero-kpi-label">Personnel</span>
            <span class="gp-hero-kpi-value">{{ FcfaFormatter::integer($context['total_staff']) }}</span>
            <span class="gp-hero-kpi-meta">membres actifs</span>
        </div>

        <div class="gp-hero-kpi">
            <span class="gp-hero-kpi-label">Établissements</span>
            <span class="gp-hero-kpi-value">{{ $context['establishment_count'] }}</span>
            <span class="gp-hero-kpi-meta">sous pilotage</span>
        </div>

        <div class="gp-hero-kpi" data-tone="{{ RateHealth::tone($rate) }}">
            <span class="gp-hero-kpi-label">Recouvrement moyen</span>
            <span class="gp-hero-kpi-value">{{ number_format($rate, 1, ',', ' ') }}&nbsp;%</span>
            <span class="gp-hero-kpi-meta">{{ RateHealth::label($rate) }}</span>
        </div>
    </x-slot:kpis>
</x-group-hero>
