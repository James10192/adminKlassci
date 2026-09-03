@php use App\Support\EtatMesure; use App\Support\FcfaFormatter; use App\Support\RateHealth; @endphp
<x-filament-panels::page>
    @php
        $establishments = $this->getComparisonData();
        $enrollment = $this->getEnrollmentData();

        // Les totaux du hero, agrégés depuis la comparaison (aucun appel service
        // supplémentaire) — mais UNIQUEMENT sur les établissements mesurés.
        //
        // La moyenne de recouvrement testait `isset($d['collection_rate'])`.
        // Or une école injoignable a bien cette clé : elle vaut 0. Les quatre
        // écoles muettes entraient donc dans la moyenne avec un 0 %, qui
        // l'écrasait, et le hero annonçait « 0,0 % — critique » en rouge. Une
        // école dont on ne sait rien ne doit peser sur aucune moyenne.
        $totalInscriptions = 0;   $nbEffectifs = 0;
        $totalStaff = 0;          $nbPersonnel = 0;
        $totalRevenueCollected = 0.0;
        $nbFinances = 0;
        $total = count($establishments);

        foreach ($establishments as $d) {
            if (EtatMesure::aUneValeur($d['etat_effectifs'] ?? null)) {
                // Des personnes distinctes, comme le KPI « Étudiants inscrits »
                // du tableau de bord (`total_students` = Σ `students`). Ce total
                // sommait les LIGNES d'inscription sous le même libellé : les
                // deux écrans affichaient deux nombres pour la même chose.
                $totalInscriptions += (int) ($d['students'] ?? 0);
                $nbEffectifs++;
            }
            if (EtatMesure::aUneValeur($d['etat_personnel'] ?? null)) {
                $totalStaff += (int) ($d['staff'] ?? 0);
                $nbPersonnel++;
            }
            if (EtatMesure::aUneValeur($d['etat_finances'] ?? null)) {
                $totalRevenueCollected += (float) ($d['revenue_collected'] ?? 0);
                $nbFinances++;
            }
        }

        // Le taux du groupe n'est PAS recalcule ici.
        //
        // Cet ecran en faisait la moyenne arithmetique des taux par ecole, la
        // ou le tableau de bord divise l'encaisse du groupe par son attendu.
        // Les deux divergent des que les ecoles n'ont pas le meme poids — 55 %
        // contre 18 % sur deux ecoles de tailles opposees — et c'est l'ecran de
        // COMPARAISON, celui qu'on ouvre pour arbitrer, qui donnait le chiffre
        // flatteur. Il lit desormais le meme producteur que le tableau de bord,
        // qui porte aussi la regle « pas d'attendu, pas de taux ».
        $kpisGroupe = $this->kpisGroupe();
        $avgRate = (float) ($kpisGroupe['collection_rate'] ?? 0);
        $tauxMesurable = $nbFinances > 0 && ($kpisGroupe['finances_mesurables'] ?? false);

        // Le ratio croise deux familles : il n'a de sens que si les DEUX sont
        // mesurées sur le même périmètre. Sinon on divise des étudiants connus
        // par un personnel inconnu.
        $ratioMesurable = $nbEffectifs > 0 && $nbEffectifs === $nbPersonnel && $totalStaff > 0;
        $avgRatio = $ratioMesurable ? round($totalInscriptions / $totalStaff, 1) : null;
    @endphp

    <x-group-hero
        title="Benchmarking"
        subtitle="Comparaison des indicateurs clés entre établissements"
        icon-path="M3 13.125C3 12.504 3.504 12 4.125 12h2.25c.621 0 1.125.504 1.125 1.125v6.75C7.5 20.496 6.996 21 6.375 21h-2.25A1.125 1.125 0 013 19.875v-6.75zM9.75 8.625c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125v11.25c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 01-1.125-1.125V8.625zM16.5 4.125c0-.621.504-1.125 1.125-1.125h2.25C20.496 3 21 3.504 21 4.125v15.75c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 01-1.125-1.125V4.125z"
    >
        <x-slot:badges>
            <span class="gp-hero-chip">{{ count($establishments) }} établissements comparés</span>
        </x-slot:badges>

        <x-slot:kpis>
            <div class="gp-hero-kpi" @if($nbEffectifs === 0) data-tone="inconnu" @endif>
                <span class="gp-hero-kpi-label">Étudiants total</span>
                <span class="gp-hero-kpi-value">
                    {{ $nbEffectifs > 0 ? FcfaFormatter::integer($totalInscriptions) : EtatMesure::TIRET }}
                </span>
                <span class="gp-hero-kpi-meta">
                    {{ $nbEffectifs === 0
                        ? EtatMesure::absenceGroupe()
                        : (EtatMesure::mentionPerimetre($nbEffectifs, $total) ?? 'cumul du groupe') }}
                </span>
            </div>

            <div class="gp-hero-kpi" @if($nbPersonnel === 0) data-tone="inconnu" @endif>
                <span class="gp-hero-kpi-label">Personnel total</span>
                <span class="gp-hero-kpi-value">
                    {{ $nbPersonnel > 0 ? FcfaFormatter::integer($totalStaff) : EtatMesure::TIRET }}
                </span>
                <span class="gp-hero-kpi-meta">
                    @if ($nbPersonnel === 0)
                        {{ EtatMesure::absenceGroupe() }}
                    @elseif ($avgRatio !== null)
                        ratio {{ $avgRatio }}:1
                    @else
                        ratio non calculable
                    @endif
                </span>
            </div>

            {{-- « 0,0 % — critique » en rouge quand rien n'a répondu annonçait un
                 effondrement du recouvrement du groupe. Sans mesure : gris. --}}
            <div class="gp-hero-kpi" data-tone="{{ $tauxMesurable ? RateHealth::tone($avgRate) : 'inconnu' }}">
                <span class="gp-hero-kpi-label">Taux de recouvrement</span>
                <span class="gp-hero-kpi-value">
                    {{ $tauxMesurable ? number_format($avgRate, 1, ',', ' ') . ' %' : EtatMesure::TIRET }}
                </span>
                <span class="gp-hero-kpi-meta">
                    @if ($nbFinances === 0)
                        {{ EtatMesure::absenceGroupe() }}
                    @elseif (! $tauxMesurable)
                        aucun montant attendu sur le périmètre mesuré
                    @else
                        {{ RateHealth::label($avgRate) }}{{ ($m = EtatMesure::mentionPerimetre($nbFinances, $total)) ? ' · ' . $m : '' }}
                    @endif
                </span>
            </div>

            {{-- Le vert était posé en dur : la tuile restait « bonne » même sur
                 un total nul faute de mesure. --}}
            <div class="gp-hero-kpi" data-tone="{{ $nbFinances > 0 ? 'success' : 'inconnu' }}">
                <span class="gp-hero-kpi-label">Encaissés cumulés</span>
                <span class="gp-hero-kpi-value">
                    {{ $nbFinances > 0 ? FcfaFormatter::compact($totalRevenueCollected) : EtatMesure::TIRET }}
                </span>
                <span class="gp-hero-kpi-meta">
                    {{ $nbFinances === 0
                        ? EtatMesure::absenceGroupe()
                        : (EtatMesure::mentionPerimetre($nbFinances, $total) ?? 'FCFA cross-établissements') }}
                </span>
            </div>
        </x-slot:kpis>
    </x-group-hero>

    {{-- Scorecard --}}
    <div class="gp-scorecard-wrap">
        <div class="gp-scorecard-header">
            <div class="gp-scorecard-title">Scorecard</div>
            <div class="gp-scorecard-desc">Comparaison des indicateurs clés par établissement</div>
        </div>
        <table class="gp-scorecard-table">
            <thead>
                <tr>
                    <th>Indicateur</th>
                    @foreach($establishments as $code => $data)
                        <th>
                            {{ $data['tenant_name'] }}
                            @unless (EtatMesure::estMesure($data['etat_finances'] ?? null) && EtatMesure::estMesure($data['etat_effectifs'] ?? null))
                                <span class="gp-scorecard-etat"
                                      title="{{ EtatMesure::libelleMotif($data['motif'] ?? null) }}">{{ EtatMesure::badge($data['etat_finances'] ?? null, $data['motif'] ?? null) }}</span>
                            @endunless
                        </th>
                    @endforeach
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td>
                        <div class="gp-scorecard-indicator">
                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M4.26 10.147a60.436 60.436 0 00-.491 6.347A48.627 48.627 0 0112 20.904a48.627 48.627 0 018.232-4.41 60.46 60.46 0 00-.491-6.347m-15.482 0a50.57 50.57 0 00-2.658-.813A59.905 59.905 0 0112 3.493a59.902 59.902 0 0110.399 5.84c-.896.248-1.783.52-2.658.814m-15.482 0A50.697 50.697 0 0112 13.489a50.702 50.702 0 017.74-3.342" /></svg>
                            Étudiants inscrits
                        </div>
                    </td>
                    @foreach($establishments as $data)
                        @php $mesure = EtatMesure::aUneValeur($data['etat_effectifs'] ?? null); @endphp
                        <td class="cell-bold {{ $mesure ? '' : 'cell-inconnu' }}"
                            @unless($mesure) title="{{ EtatMesure::libelleMotif($data['motif'] ?? null) }}" @endunless>
                            {{ $mesure ? FcfaFormatter::full((float) ($data['students'] ?? 0)) : EtatMesure::TIRET }}
                        </td>
                    @endforeach
                </tr>
                <tr>
                    <td>
                        <div class="gp-scorecard-indicator">
                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M18 18.72a9.094 9.094 0 003.741-.479 3 3 0 00-4.682-2.72m.94 3.198l.001.031c0 .225-.012.447-.037.666A11.944 11.944 0 0112 21c-2.17 0-4.207-.576-5.963-1.584A6.062 6.062 0 016 18.719m12 0a5.971 5.971 0 00-.941-3.197m0 0A5.995 5.995 0 0012 12.75a5.995 5.995 0 00-5.058 2.772m0 0a3 3 0 00-4.681 2.72 8.986 8.986 0 003.74.477m.94-3.197a5.971 5.971 0 00-.94 3.197M15 6.75a3 3 0 11-6 0 3 3 0 016 0zm6 3a2.25 2.25 0 11-4.5 0 2.25 2.25 0 014.5 0zm-13.5 0a2.25 2.25 0 11-4.5 0 2.25 2.25 0 014.5 0z" /></svg>
                            Personnel
                        </div>
                    </td>
                    @foreach($establishments as $data)
                        @php $mesure = EtatMesure::aUneValeur($data['etat_personnel'] ?? null); @endphp
                        <td class="{{ $mesure ? '' : 'cell-inconnu' }}"
                            @unless($mesure) title="{{ EtatMesure::libelleMotif($data['motif'] ?? null) }}" @endunless>
                            {{ $mesure ? ($data['staff'] ?? 0) : EtatMesure::TIRET }}
                        </td>
                    @endforeach
                </tr>
                <tr>
                    <td>
                        <div class="gp-scorecard-indicator">
                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 3v17.25m0 0c-1.472 0-2.882.265-4.185.75M12 20.25c1.472 0 2.882.265 4.185.75M18.75 4.97A48.416 48.416 0 0012 4.5c-2.291 0-4.545.16-6.75.47m13.5 0c1.01.143 2.01.317 3 .52m-3-.52l2.62 10.726c.122.499-.106 1.028-.589 1.202a5.988 5.988 0 01-2.031.352 5.988 5.988 0 01-2.031-.352c-.483-.174-.711-.703-.59-1.202L18.75 4.971zm-16.5.52c.99-.203 1.99-.377 3-.52m0 0l2.62 10.726c.122.499-.106 1.028-.589 1.202a5.989 5.989 0 01-2.031.352 5.989 5.989 0 01-2.031-.352c-.483-.174-.711-.703-.59-1.202L5.25 4.971z" /></svg>
                            Ratio étudiants/personnel
                        </div>
                    </td>
                    @foreach($establishments as $data)
                        @php
                            // Un ratio croise deux familles : les deux doivent être
                            // mesurées. « 0:1 » se lisait comme un établissement sans
                            // aucun étudiant par enseignant.
                            $mesure = EtatMesure::aUneValeur($data['etat_effectifs'] ?? null)
                                && EtatMesure::aUneValeur($data['etat_personnel'] ?? null)
                                && ($data['staff'] ?? 0) > 0;
                            $ratio = $mesure ? round(($data['students'] ?? 0) / $data['staff'], 1) : null;
                        @endphp
                        <td class="{{ $mesure ? '' : 'cell-inconnu' }}"
                            @unless($mesure) title="{{ EtatMesure::libelleMotif($data['motif'] ?? null) }}" @endunless>
                            {{ $mesure ? $ratio . ':1' : EtatMesure::TIRET }}
                        </td>
                    @endforeach
                </tr>
                <tr>
                    <td>
                        <div class="gp-scorecard-indicator">
                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 18.75a60.07 60.07 0 0115.797 2.101c.727.198 1.453-.342 1.453-1.096V18.75M3.75 4.5v.75A.75.75 0 013 6h-.75m0 0v-.375c0-.621.504-1.125 1.125-1.125H20.25M2.25 6v9m18-10.5v.75c0 .414.336.75.75.75h.75m-1.5-1.5h.375c.621 0 1.125.504 1.125 1.125v9.75c0 .621-.504 1.125-1.125 1.125h-.375m1.5-1.5H21a.75.75 0 00-.75.75v.75m0 0H3.75m0 0h-.375a1.125 1.125 0 01-1.125-1.125V15m1.5 1.5v-.75A.75.75 0 003 15h-.75M15 10.5a3 3 0 11-6 0 3 3 0 016 0zm3 0h.008v.008H18V10.5zm-12 0h.008v.008H6V10.5z" /></svg>
                            Taux de recouvrement
                        </div>
                    </td>
                    @foreach($establishments as $data)
                        @php
                            // La pastille ROUGE « 0% » accusait chaque école muette
                            // de n'avoir rien encaissé. Sans mesure : gris, tiret.
                            $mesure = EtatMesure::aUneValeur($data['etat_finances'] ?? null);
                            $rateClass = $mesure ? RateHealth::tone((float) ($data['collection_rate'] ?? 0)) : 'inconnu';
                        @endphp
                        <td @unless($mesure) title="{{ EtatMesure::libelleMotif($data['motif'] ?? null) }}" @endunless>
                            <span class="gp-rate-badge {{ $rateClass }}">
                                {{ $mesure ? ($data['collection_rate'] ?? 0) . '%' : EtatMesure::TIRET }}
                            </span>
                        </td>
                    @endforeach
                </tr>
                <tr>
                    <td>
                        <div class="gp-scorecard-indicator">
                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6v12m-3-2.818l.879.659c1.171.879 3.07.879 4.242 0 1.172-.879 1.172-2.303 0-3.182C13.536 12.219 12.768 12 12 12c-.725 0-1.45-.22-2.003-.659-1.106-.879-1.106-2.303 0-3.182s2.9-.879 4.006 0l.415.33M21 12a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
                            Revenus encaissés
                        </div>
                    </td>
                    @foreach($establishments as $data)
                        @php $mesure = EtatMesure::aUneValeur($data['etat_finances'] ?? null); @endphp
                        {{-- Le vert était en dur : « 0,0 M » s'affichait en couleur
                             de succès pour une école dont la base n'avait pas répondu. --}}
                        <td class="cell-bold {{ $mesure ? '' : 'cell-inconnu' }}"
                            @if($mesure) style="color: var(--gp-success)"
                            @else title="{{ EtatMesure::libelleMotif($data['motif'] ?? null) }}" @endif>
                            {{ $mesure ? FcfaFormatter::millions((float) ($data['revenue_collected'] ?? 0)) . ' M' : EtatMesure::TIRET }}
                        </td>
                    @endforeach
                </tr>
                <tr>
                    <td>
                        <div class="gp-scorecard-indicator">
                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12h3.75M9 15h3.75M9 18h3.75m3 .75H18a2.25 2.25 0 002.25-2.25V6.108c0-1.135-.845-2.098-1.976-2.192a48.424 48.424 0 00-1.123-.08m-5.801 0c-.065.21-.1.433-.1.664 0 .414.336.75.75.75h4.5a.75.75 0 00.75-.75 2.25 2.25 0 00-.1-.664m-5.8 0A2.251 2.251 0 0113.5 2.25H15c1.012 0 1.867.668 2.15 1.586m-5.8 0c-.376.023-.75.05-1.124.08C9.095 4.01 8.25 4.973 8.25 6.108V8.25m0 0H4.875c-.621 0-1.125.504-1.125 1.125v11.25c0 .621.504 1.125 1.125 1.125h9.75c.621 0 1.125-.504 1.125-1.125V9.375c0-.621-.504-1.125-1.125-1.125H8.25zM6.75 12h.008v.008H6.75V12zm0 3h.008v.008H6.75V15zm0 3h.008v.008H6.75V18z" /></svg>
                            Présences ({{ mb_strtolower($this->periodeMesure()->label()) }})
                        </div>
                    </td>
                    @foreach($establishments as $data)
                        @php $mesure = EtatMesure::aUneValeur($data['etat_assiduite'] ?? null); @endphp
                        {{-- « N/A » confondait « pas de présences saisies » et
                             « base injoignable ». L'infobulle tranche. --}}
                        <td class="{{ $mesure ? '' : 'cell-inconnu' }}"
                            @unless($mesure) title="{{ EtatMesure::libelleMotif($data['motif'] ?? null) }}" @endunless>
                            {{ $mesure ? ($data['attendance_rate'] ?? 0) . '%' : EtatMesure::TIRET }}
                        </td>
                    @endforeach
                </tr>
            </tbody>
        </table>
    </div>

    {{-- Enrollment by filiere --}}
    <div class="gp-filiere-grid">
        @foreach($enrollment as $code => $data)
            <div class="gp-filiere-card">
                <div class="gp-filiere-card-header">
                    <div class="gp-filiere-card-title">{{ $data['tenant_name'] }}</div>
                    <div class="gp-filiere-card-subtitle">Répartition par filière</div>
                </div>
                <div class="gp-filiere-card-body">
                    @forelse($data['filieres'] ?? [] as $filiere)
                        <div class="gp-filiere-row">
                            <span class="gp-filiere-name">{{ $filiere->filiere_name }}</span>
                            <span class="gp-filiere-count">{{ $filiere->count }}</span>
                        </div>
                    @empty
                        {{-- « Aucune donnée » couvrait trois cas differents : base
                             injoignable, annee non ouverte, et ecole reellement
                             sans inscrit. Seul le dernier est une absence de
                             donnee — les deux autres appellent une action. --}}
                        @if (EtatMesure::aUneValeur($data['etat'] ?? null))
                            <div class="gp-filiere-empty">Aucun étudiant inscrit cette année</div>
                        @else
                            <div class="gp-filiere-empty gp-filiere-empty--inconnu">
                                {{ EtatMesure::badge($data['etat'] ?? null, $data['motif'] ?? null) }}
                                — {{ EtatMesure::libelleMotif($data['motif'] ?? null) }}
                            </div>
                        @endif
                    @endforelse
                </div>
            </div>
        @endforeach
    </div>
</x-filament-panels::page>
