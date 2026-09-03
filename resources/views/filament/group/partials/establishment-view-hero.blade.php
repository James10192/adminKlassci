@php use App\Support\EtatMesure; use App\Support\FcfaFormatter; use App\Support\RateHealth; @endphp

@php
    /** @var \App\Models\Tenant $tenant */
    /** @var array<string,mixed> $kpis */
    $students = (int) ($kpis['students'] ?? $kpis['inscriptions'] ?? 0);
    $staff = (int) ($kpis['staff'] ?? 0);
    $rate = (float) ($kpis['collection_rate'] ?? 0);
    $academicYear = $kpis['academic_year'] ?? null;

    // Ce hero etait le dernier a afficher la ligne de zeros telle quelle :
    // « 0 etudiant », « 0 personnel », « 0,0 % — critique » en ROUGE, et
    // « Annee universitaire N/A — synchro temps reel ». Un fondateur ouvrant la
    // fiche d'une ecole injoignable y lisait une ecole vide et en faillite de
    // recouvrement, sous une mention qui affirmait la fraicheur du chiffre.
    //
    // Un etat absent vaut MESURE : le partial est aussi rendu avec un tableau
    // de KPI nu par les tests et par les appels historiques.
    $etat = static fn (string $cle): string => $kpis[$cle] ?? EtatMesure::MESURE;
    $motif = $kpis['motif'] ?? null;

    $effectifsMesure = EtatMesure::aUneValeur($etat('etat_effectifs'));
    $personnelMesure = EtatMesure::aUneValeur($etat('etat_personnel'));
    $financesMesure = EtatMesure::aUneValeur($etat('etat_finances'));

    // L'annee est connue des que la base a repondu : c'est justement elle qui
    // manque quand aucune annee n'est ouverte, et le motif le dit.
    $anneeConnue = $academicYear !== null && $academicYear !== '' && $academicYear !== 'N/A';

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
        <div class="gp-hero-kpi" @unless($effectifsMesure) data-tone="inconnu" @endunless>
            <span class="gp-hero-kpi-label">Étudiants inscrits</span>
            <span class="gp-hero-kpi-value">
                {{ $effectifsMesure ? FcfaFormatter::integer($students) : EtatMesure::TIRET }}
            </span>
            <span class="gp-hero-kpi-meta">
                {{ $effectifsMesure ? 'année en cours' : EtatMesure::libelleMotif($motif) }}
            </span>
        </div>

        <div class="gp-hero-kpi" @unless($personnelMesure) data-tone="inconnu" @endunless>
            <span class="gp-hero-kpi-label">Personnel</span>
            <span class="gp-hero-kpi-value">
                {{ $personnelMesure ? FcfaFormatter::integer($staff) : EtatMesure::TIRET }}
            </span>
            <span class="gp-hero-kpi-meta">
                {{ $personnelMesure ? 'membres actifs' : EtatMesure::libelleMotif($motif) }}
            </span>
        </div>

        {{-- « 0,0 % — critique » en rouge sur une ecole dont on ne sait rien
             accusait l'ecole d'un effondrement qui n'etait qu'une panne. --}}
        <div class="gp-hero-kpi" data-tone="{{ $financesMesure ? RateHealth::tone($rate) : 'inconnu' }}">
            <span class="gp-hero-kpi-label">Recouvrement</span>
            <span class="gp-hero-kpi-value">
                {{ $financesMesure ? number_format($rate, 1, ',', ' ') . ' %' : EtatMesure::TIRET }}
            </span>
            <span class="gp-hero-kpi-meta">
                {{ $financesMesure ? RateHealth::label($rate) : EtatMesure::libelleMotif($motif) }}
            </span>
        </div>

        {{-- « synchro temps reel » se calculait sur le STATUT de l'abonnement,
             jamais sur la fraicheur du chiffre : la mention affirmait donc la
             fraicheur d'un « N/A ». --}}
        <div class="gp-hero-kpi" @unless($anneeConnue) data-tone="inconnu" @endunless>
            <span class="gp-hero-kpi-label">Année universitaire</span>
            <span class="gp-hero-kpi-value" style="font-size: 1.15rem;">
                {{ $anneeConnue ? $academicYear : EtatMesure::TIRET }}
            </span>
            <span class="gp-hero-kpi-meta">
                @if ($anneeConnue)
                    mesuré à l'instant
                @elseif ($motif === EtatMesure::MOTIF_SANS_ANNEE)
                    {{ EtatMesure::libelleMotif(EtatMesure::MOTIF_SANS_ANNEE) }}
                @else
                    {{ EtatMesure::libelleMotif($motif) }}
                @endif
            </span>
        </div>
    </x-slot:kpis>
</x-group-hero>
