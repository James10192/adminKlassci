@php use App\Support\EtatMesure; use App\Support\FcfaFormatter; use App\Support\RateHealth; @endphp
<x-filament-panels::page>
    @php
        $financials = $this->getFinancials();
        $totals = $this->getTotals();
        $rate = (float) ($totals['rate'] ?? 0);
        $outstanding = (float) ($totals['outstanding'] ?? 0);
        $surplus = (float) ($totals['surplus'] ?? 0);
        $paie = $this->getPayroll();
        $resultat = $this->getResultat();

        $perimetre = $totals['perimetre'] ?? [];
        $finMesure = EtatMesure::estMesure($perimetre['etat'] ?? EtatMesure::MESURE);
        $finMention = EtatMesure::mentionPerimetre(
            $perimetre['repondu'] ?? 0,
            $perimetre['total'] ?? 0,
        );
    @endphp

    <x-group-hero
        title="Vue financière"
        subtitle="Consolidation des revenus et encaissements du groupe"
        icon-path="M2.25 18.75a60.07 60.07 0 0115.797 2.101c.727.198 1.453-.342 1.453-1.096V18.75M3.75 4.5v.75A.75.75 0 013 6h-.75m0 0v-.375c0-.621.504-1.125 1.125-1.125H20.25M2.25 6v9m18-10.5v.75c0 .414.336.75.75.75h.75m-1.5-1.5h.375c.621 0 1.125.504 1.125 1.125v9.75c0 .621-.504 1.125-1.125 1.125h-.375m1.5-1.5H21a.75.75 0 00-.75.75v.75m0 0H3.75m0 0h-.375a1.125 1.125 0 01-1.125-1.125V15m1.5 1.5v-.75A.75.75 0 003 15h-.75M15 10.5a3 3 0 11-6 0 3 3 0 016 0zm3 0h.008v.008H18V10.5zm-12 0h.008v.008H6V10.5z"
    >
        <x-slot:badges>
            <span class="gp-hero-chip">Année universitaire en cours</span>
            <span class="gp-hero-chip">Montants en FCFA</span>
        </x-slot:badges>

        <x-slot:kpis>
            <div class="gp-hero-kpi" data-tone="{{ $finMesure ? '' : 'inconnu' }}">
                <span class="gp-hero-kpi-label">Revenus attendus</span>
                <span class="gp-hero-kpi-value">{{ $finMesure ? FcfaFormatter::compact((float) ($totals['expected'] ?? 0)) : EtatMesure::TIRET }}</span>
                <span class="gp-hero-kpi-meta">{{ $finMesure ? ($finMention ?? 'cross-établissements') : EtatMesure::absenceGroupe() }}</span>
            </div>

            <div class="gp-hero-kpi" data-tone="{{ $finMesure ? 'success' : 'inconnu' }}">
                <span class="gp-hero-kpi-label">Encaissés</span>
                <span class="gp-hero-kpi-value">{{ $finMesure ? FcfaFormatter::compact((float) ($totals['collected'] ?? 0)) : EtatMesure::TIRET }}</span>
                <span class="gp-hero-kpi-meta">{{ $finMesure ? ($finMention ?? 'paiements validés') : EtatMesure::absenceGroupe() }}</span>
            </div>

            {{-- Un solde nul non mesure se lisait « Surplus », en vert. Le
                 libelle lui-meme dependait d'un chiffre qu'on n'avait pas. --}}
            <div class="gp-hero-kpi" data-tone="{{ ! $finMesure ? 'inconnu' : ($outstanding > 0 ? 'danger' : 'success') }}">
                <span class="gp-hero-kpi-label">{{ ! $finMesure ? 'Impayés' : ($outstanding > 0 ? 'Impayés' : 'Surplus') }}</span>
                <span class="gp-hero-kpi-value">{{ $finMesure ? FcfaFormatter::compact(max($outstanding, $surplus)) : EtatMesure::TIRET }}</span>
                <span class="gp-hero-kpi-meta">
                    {{ ! $finMesure ? EtatMesure::absenceGroupe() : ($outstanding > 0 ? 'à recouvrer' : 'trop-perçu') }}
                </span>
            </div>

            <div class="gp-hero-kpi" data-tone="{{ $finMesure ? RateHealth::tone($rate) : 'inconnu' }}">
                <span class="gp-hero-kpi-label">Taux de recouvrement</span>
                <span class="gp-hero-kpi-value">{{ $finMesure ? number_format($rate, 1, ',', ' ') . ' %' : EtatMesure::TIRET }}</span>
                <span class="gp-hero-kpi-meta">{{ $finMesure ? RateHealth::label($rate) : EtatMesure::absenceGroupe() }}</span>
            </div>

            {{-- « 0 FCFA · 0 enseignant · 0 bulletin » quand aucune base n'a
                 repondu : le portrait d'un groupe sans personnel, alors que ses
                 ecoles en emploient des dizaines. --}}
            <div class="gp-hero-kpi" data-tone="{{ $resultat['cout_mesure'] ? '' : 'inconnu' }}">
                <span class="gp-hero-kpi-label">Masse salariale</span>
                <span class="gp-hero-kpi-value">
                    {{ $resultat['cout_mesure'] ? FcfaFormatter::compact($resultat['cout']) : EtatMesure::TIRET }}
                </span>
                <span class="gp-hero-kpi-meta">
                    @if (! $resultat['cout_mesure'])
                        {{ EtatMesure::absenceGroupe() }}
                    @else
                        {{ $paie['enseignants'] }} enseignant{{ $paie['enseignants'] > 1 ? 's' : '' }} ·
                        {{ $paie['bulletins'] }} bulletin{{ $paie['bulletins'] > 1 ? 's' : '' }}
                    @endif
                </span>
            </div>

            {{-- Encaisse moins le cout enseignant. Si un etablissement n'a pas
                 repondu, son cout manque et le resultat parait meilleur qu'il
                 ne l'est : on le dit plutot que d'afficher un chiffre net.
                 Et si RIEN n'a repondu, `0 - 0 = 0` n'est pas un resultat
                 equilibre : c'est une soustraction entre deux inconnues. --}}
            <div class="gp-hero-kpi" data-tone="{{ ! $resultat['net_mesure'] ? 'inconnu' : (! $resultat['complet'] ? 'warning' : ($resultat['net'] >= 0 ? 'success' : 'danger')) }}">
                <span class="gp-hero-kpi-label">Reste après paie</span>
                <span class="gp-hero-kpi-value">
                    {{ $resultat['net_mesure'] ? FcfaFormatter::compact($resultat['net']) : EtatMesure::TIRET }}
                </span>
                <span class="gp-hero-kpi-meta">
                    @if (! $resultat['net_mesure'])
                        {{ EtatMesure::absenceGroupe() }}
                    @elseif (! $resultat['complet'])
                        {{-- « non consolidé » disait autre chose : en SYSCOHADA
                             comme en IFRS, c'est « retraitements non effectués »,
                             pas « manquant ». Devant un banquier, la ligne
                             affirmait un fait comptable qu'on ne voulait pas dire. --}}
                        {{ $resultat['manquants'] }} établissement{{ $resultat['manquants'] > 1 ? 's' : '' }} non mesuré{{ $resultat['manquants'] > 1 ? 's' : '' }}
                    @else
                        encaissé moins masse salariale
                    @endif
                </span>
            </div>
        </x-slot:kpis>
    </x-group-hero>

    {{-- Comparison table --}}
    <div class="gp-fin-table-wrap">
        <div class="gp-fin-table-header">
            <div>
                <div class="gp-fin-table-title">Comparaison par établissement</div>
                <div class="gp-fin-table-subtitle">Année universitaire en cours</div>
            </div>
            <span class="gp-fin-table-badge">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6v12m-3-2.818l.879.659c1.171.879 3.07.879 4.242 0 1.172-.879 1.172-2.303 0-3.182C13.536 12.219 12.768 12 12 12c-.725 0-1.45-.22-2.003-.659-1.106-.879-1.106-2.303 0-3.182s2.9-.879 4.006 0l.415.33M21 12a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
                Montants en FCFA
            </span>
        </div>
        <table class="gp-fin-table">
            <thead>
                <tr>
                    <th>Établissement</th>
                    <th>Attendu</th>
                    <th>Encaissé</th>
                    <th>Reste</th>
                    <th style="text-align:center">Taux</th>
                    <th style="text-align:center; min-width:120px">Progression</th>
                </tr>
            </thead>
            <tbody>
                @foreach($financials as $code => $data)
                    @php
                        $mesure = EtatMesure::estMesure($data['etat'] ?? EtatMesure::MESURE);
                        $rateClass = $mesure ? RateHealth::tone((float) $data['collection_rate']) : 'inconnu';
                    @endphp
                    <tr @class(['gp-fin-row--inconnu' => ! $mesure])>
                        <td>
                            <div class="cell-name">
                                <div class="cell-icon blue">
                                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 21h16.5M4.5 3h15M5.25 3v18m13.5-18v18M9 6.75h1.5m-1.5 3h1.5m-1.5 3h1.5m3-6H15m-1.5 3H15m-1.5 3H15M9 21v-3.375c0-.621.504-1.125 1.125-1.125h3.75c.621 0 1.125.504 1.125 1.125V21" /></svg>
                                </div>
                                <span>
                                    {{ $data['tenant_name'] }}
                                    @unless($mesure)
                                        <span class="gp-fin-etat">{{ EtatMesure::libelleMotif($data['motif'] ?? null) }}</span>
                                    @endunless
                                </span>
                            </div>
                        </td>
                        <td>{{ $mesure ? FcfaFormatter::full((float) $data['revenue_expected']) : EtatMesure::TIRET }}</td>
                        <td class="{{ $mesure ? 'cell-green' : '' }}">{{ $mesure ? FcfaFormatter::full((float) $data['revenue_collected']) : EtatMesure::TIRET }}</td>
                        <td class="{{ $mesure && ($data['outstanding'] ?? 0) > 0 ? 'cell-red' : '' }}">{{ $mesure ? FcfaFormatter::full((float) ($data['outstanding'] ?? 0)) : EtatMesure::TIRET }}</td>
                        <td style="text-align:center">
                            <span class="gp-rate-badge {{ $rateClass }}">{{ $mesure ? $data['collection_rate'] . '%' : EtatMesure::TIRET }}</span>
                        </td>
                        <td style="text-align:center">
                            @if($mesure)
                                <div class="gp-progress-track">
                                    <div class="gp-progress-bar {{ $rateClass }}" style="width: {{ min($data['collection_rate'], 100) }}%"></div>
                                </div>
                            @else
                                <div class="gp-progress-track gp-progress-track--inconnu"></div>
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
            <tfoot>
                <tr>
                    <td>
                        <div class="cell-name">
                            <div class="cell-icon gray">
                                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 15.75V18m-7.5-6.75h.008v.008H8.25v-.008zm0 2.25h.008v.008H8.25V13.5zm0 2.25h.008v.008H8.25v-.008zm0 2.25h.008v.008H8.25V18zm2.498-6.75h.007v.008h-.007v-.008zm0 2.25h.007v.008h-.007V13.5zm0 2.25h.007v.008h-.007v-.008zm0 2.25h.007v.008h-.007V18zm2.504-6.75h.008v.008h-.008v-.008zm0 2.25h.008v.008h-.008V13.5zm0 2.25h.008v.008h-.008v-.008zm0 2.25h.008v.008h-.008V18zm2.498-6.75h.008v.008H18v-.008zm0 2.25h.008v.008H18V13.5zM9.75 9h4.5" /></svg>
                            </div>
                            <span>
                                TOTAL GROUPE
                                @if($finMention)
                                    <span class="gp-fin-etat">{{ $finMention }}</span>
                                @endif
                            </span>
                        </div>
                    </td>
                    <td>{{ $finMesure ? FcfaFormatter::full((float) $totals['expected']) : EtatMesure::TIRET }}</td>
                    <td class="{{ $finMesure ? 'cell-green' : '' }}">{{ $finMesure ? FcfaFormatter::full((float) $totals['collected']) : EtatMesure::TIRET }}</td>
                    <td>{{ $finMesure ? FcfaFormatter::full((float) $totals['outstanding']) : EtatMesure::TIRET }}</td>
                    <td style="text-align:center">
                        <span class="gp-rate-badge {{ $finMesure ? 'primary' : 'inconnu' }}">{{ $finMesure ? $totals['rate'] . '%' : EtatMesure::TIRET }}</span>
                    </td>
                    <td style="text-align:center">
                        @if($finMesure)
                            <div class="gp-progress-track">
                                <div class="gp-progress-bar success" style="width: {{ min($totals['rate'], 100) }}%; background: linear-gradient(90deg, var(--gp-primary), #5e91de);"></div>
                            </div>
                        @else
                            <div class="gp-progress-track gp-progress-track--inconnu"></div>
                        @endif
                    </td>
                </tr>
            </tfoot>
        </table>
    </div>
</x-filament-panels::page>
