@php use App\Support\EtatMesure; use App\Support\FcfaFormatter; use App\Support\RateHealth; @endphp

@php
    $rate = (float) ($context['avg_rate'] ?? 0);

    // Le nombre d'établissements, lui, est toujours mesuré : il vient de
    // klassci_master, qui ne tombe pas en même temps que les bases des écoles.
    $count = (int) ($context['establishment_count'] ?? 0);

    // Un état absent vaut MESURE : c'est le contrat de rétrocompatibilité
    // d'EtatMesure, et un partial appelé sans état ne doit pas tomber.
    $etat = static fn (string $cle): string => $context[$cle] ?? EtatMesure::MESURE;

    $effectifsMesure = EtatMesure::aUneValeur($etat('etat_effectifs'));
    $personnelMesure = EtatMesure::aUneValeur($etat('etat_personnel'));
    $financesMesure = EtatMesure::aUneValeur($etat('etat_finances'));
@endphp

<x-group-hero
    title="Mes Établissements"
    subtitle="Pilotage centralisé des établissements du groupe"
    icon-path="M2.25 21h19.5m-18-18v18m10.5-18v18m6-13.5V21M6.75 6.75h.75m-.75 3h.75m-.75 3h.75m3-6h.75m-.75 3h.75m-.75 3h.75M6.75 21v-3.375c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125V21M3 3h12m-.75 4.5H21m-3.75 3h.008v.008h-.008v-.008zm0 3h.008v.008h-.008v-.008zm0 3h.008v.008h-.008v-.008z"
>
    <x-slot:badges>
        <span class="gp-hero-chip">{{ $count }} {{ \Illuminate\Support\Str::plural('établissement', $count) }}</span>
    </x-slot:badges>

    <x-slot:kpis>
        <div class="gp-hero-kpi" @if(! $effectifsMesure) data-tone="inconnu" @endif>
            <span class="gp-hero-kpi-label">Étudiants inscrits</span>
            <span class="gp-hero-kpi-value">
                {{ $effectifsMesure
                    ? FcfaFormatter::integer($context['total_students'] ?? 0)
                    : EtatMesure::TIRET }}
            </span>
            <span class="gp-hero-kpi-meta">
                @if (! $effectifsMesure)
                    {{ EtatMesure::absenceGroupe() }}
                @else
                    {{ $context['mention_effectifs'] ?? 'cumul cross-groupe' }}
                @endif
            </span>
        </div>

        <div class="gp-hero-kpi" @if(! $personnelMesure) data-tone="inconnu" @endif>
            <span class="gp-hero-kpi-label">Personnel</span>
            <span class="gp-hero-kpi-value">
                {{ $personnelMesure
                    ? FcfaFormatter::integer($context['total_staff'] ?? 0)
                    : EtatMesure::TIRET }}
            </span>
            <span class="gp-hero-kpi-meta">
                @if (! $personnelMesure)
                    {{ EtatMesure::absenceGroupe() }}
                @else
                    {{ $context['mention_personnel'] ?? 'membres actifs' }}
                @endif
            </span>
        </div>

        <div class="gp-hero-kpi">
            <span class="gp-hero-kpi-label">Établissements</span>
            <span class="gp-hero-kpi-value">{{ $count }}</span>
            <span class="gp-hero-kpi-meta">sous pilotage</span>
        </div>

        {{-- Sans mesure, ni rouge ni vert : un taux qu'on n'a pas n'est pas
             « critique », il est inconnu. La tuile rouge « 0,0 % critique »
             annonçait au fondateur un effondrement du recouvrement alors que
             seule la connexion avait échoué. --}}
        <div class="gp-hero-kpi" data-tone="{{ $financesMesure ? RateHealth::tone($rate) : 'inconnu' }}">
            <span class="gp-hero-kpi-label">Recouvrement moyen</span>
            <span class="gp-hero-kpi-value">
                {{ $financesMesure ? number_format($rate, 1, ',', ' ') . ' %' : EtatMesure::TIRET }}
            </span>
            <span class="gp-hero-kpi-meta">
                @if (! $financesMesure)
                    {{ EtatMesure::absenceGroupe() }}
                @else
                    {{ RateHealth::label($rate) }}{{ ($context['mention_finances'] ?? null) ? ' · ' . $context['mention_finances'] : '' }}
                @endif
            </span>
        </div>
    </x-slot:kpis>
</x-group-hero>
