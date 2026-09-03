@php use App\Support\EtatMesure; use App\Support\FcfaFormatter; use App\Support\RateHealth; @endphp

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

    // Le perimetre de chaque famille. Un total qui ne couvre pas tous les
    // etablissements le dit sous sa propre valeur — pas dans une note de bas
    // de page qu'on ne lit pas.
    $perimetre = $context['perimetre'] ?? [];
    $portee = function (string $famille) use ($perimetre): array {
        $p = $perimetre[$famille] ?? null;

        if ($p === null) {
            return ['etat' => EtatMesure::MESURE, 'mention' => null];
        }

        return [
            'etat' => $p['etat'] ?? EtatMesure::MESURE,
            'mention' => EtatMesure::mentionReleves($p['releves'] ?? 0)
                ?? EtatMesure::mentionPerimetre($p['repondu'] ?? 0, $p['total'] ?? 0),
        ];
    };

    $pEffectifs = $portee('effectifs');
    $pPersonnel = $portee('personnel');
    $pFinances  = $portee('finances');
    $pAssiduite = $portee('assiduite');
@endphp

<x-group-hero
    :title="$context['group_name']"
    :subtitle="'Bienvenue, ' . $context['user_name'] . ' — ' . $context['role']"
    icon-path="M2.25 21h19.5m-18-18v18m10.5-18v18m6-13.5V21M6.75 6.75h.75m-.75 3h.75m-.75 3h.75m3-6h.75m-.75 3h.75m-.75 3h.75M6.75 21v-3.375c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125V21M3 3h12m-.75 4.5H21m-3.75 3h.008v.008h-.008v-.008zm0 3h.008v.008h-.008v-.008zm0 3h.008v.008h-.008v-.008z"
>
    @if(! empty($actions))
        <x-slot:actions>
            @foreach($actions as $action)
                {{ $action }}
            @endforeach
        </x-slot:actions>
    @endif

    <x-slot:badges>
        <span class="gp-hero-chip">
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
            Mesuré&nbsp;: {{ $context['last_sync'] }}
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
        <div class="gp-hero-kpi" data-tone="{{ EtatMesure::estMesure($pEffectifs['etat']) ? '' : 'inconnu' }}">
            <span class="gp-hero-kpi-label">Étudiants inscrits</span>
            @if(EtatMesure::aUneValeur($pEffectifs['etat']))
                <span class="gp-hero-kpi-value">{{ FcfaFormatter::integer($totalStudents) }}</span>
                <span class="gp-hero-kpi-meta">
                    {{ $pEffectifs['mention']
                        ?? $establishmentCount . ' ' . \Illuminate\Support\Str::plural('établissement', $establishmentCount) }}
                </span>
            @else
                <span class="gp-hero-kpi-value">{{ EtatMesure::TIRET }}</span>
                <span class="gp-hero-kpi-meta">{{ EtatMesure::absenceGroupe() }}</span>
            @endif
        </div>

        <div class="gp-hero-kpi" data-tone="{{ EtatMesure::estMesure($pFinances['etat']) ? RateHealth::tone($collectionRate) : 'inconnu' }}">
            <span class="gp-hero-kpi-label">Recouvrement</span>
            @if(EtatMesure::estMesure($pFinances['etat']))
                <span class="gp-hero-kpi-value">{{ number_format($collectionRate, 1, ',', ' ') }}&nbsp;%</span>
                <span class="gp-hero-kpi-meta">
                    {{ FcfaFormatter::compact($revenueCollected) }} FCFA encaissés{{ $pFinances['mention'] ? ' · ' . $pFinances['mention'] : '' }}
                </span>
            @else
                <span class="gp-hero-kpi-value">{{ EtatMesure::TIRET }}</span>
                <span class="gp-hero-kpi-meta">{{ EtatMesure::absenceGroupe() }}</span>
            @endif
        </div>

        <div class="gp-hero-kpi" data-tone="{{ EtatMesure::estMesure($pPersonnel['etat']) ? '' : 'inconnu' }}">
            <span class="gp-hero-kpi-label">Personnel</span>
            @if(EtatMesure::aUneValeur($pPersonnel['etat']))
                <span class="gp-hero-kpi-value">{{ FcfaFormatter::integer((int) ($k['total_staff'] ?? 0)) }}</span>
                <span class="gp-hero-kpi-meta">{{ $pPersonnel['mention'] ?? 'membres cross-groupe' }}</span>
            @else
                <span class="gp-hero-kpi-value">{{ EtatMesure::TIRET }}</span>
                <span class="gp-hero-kpi-meta">{{ EtatMesure::absenceGroupe() }}</span>
            @endif
        </div>

        <div class="gp-hero-kpi" data-tone="{{ EtatMesure::estMesure($pAssiduite['etat']) ? '' : 'inconnu' }}">
            <span class="gp-hero-kpi-label">Assiduité</span>
            @if(EtatMesure::estMesure($pAssiduite['etat']))
                <span class="gp-hero-kpi-value">{{ number_format((float) ($k['avg_attendance_rate'] ?? 0), 1, ',', ' ') }}&nbsp;%</span>
                <span class="gp-hero-kpi-meta">{{ $pAssiduite['mention'] ?? 'moyenne pondérée' }}</span>
            @else
                <span class="gp-hero-kpi-value">{{ EtatMesure::TIRET }}</span>
                <span class="gp-hero-kpi-meta">{{ EtatMesure::absenceGroupe() }}</span>
            @endif
        </div>
    </x-slot:kpis>
</x-group-hero>
