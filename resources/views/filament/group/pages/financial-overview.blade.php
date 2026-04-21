@php use App\Support\FcfaFormatter; use App\Support\RateHealth; @endphp
<x-filament-panels::page>
    @php
        $financials = $this->getFinancials();
        $totals = $this->getTotals();
        $rate = (float) ($totals['rate'] ?? 0);
        $outstanding = (float) ($totals['outstanding'] ?? 0);
        $surplus = (float) ($totals['surplus'] ?? 0);
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
            <div class="gp-hero-kpi">
                <span class="gp-hero-kpi-label">Revenus attendus</span>
                <span class="gp-hero-kpi-value">{{ FcfaFormatter::compact((float) ($totals['expected'] ?? 0)) }}</span>
                <span class="gp-hero-kpi-meta">cross-établissements</span>
            </div>

            <div class="gp-hero-kpi" data-tone="success">
                <span class="gp-hero-kpi-label">Encaissés</span>
                <span class="gp-hero-kpi-value">{{ FcfaFormatter::compact((float) ($totals['collected'] ?? 0)) }}</span>
                <span class="gp-hero-kpi-meta">paiements validés</span>
            </div>

            <div class="gp-hero-kpi" data-tone="{{ $outstanding > 0 ? 'danger' : 'success' }}">
                <span class="gp-hero-kpi-label">{{ $outstanding > 0 ? 'Impayés' : 'Surplus' }}</span>
                <span class="gp-hero-kpi-value">{{ FcfaFormatter::compact(max($outstanding, $surplus)) }}</span>
                <span class="gp-hero-kpi-meta">{{ $outstanding > 0 ? 'à recouvrer' : 'trop-perçu' }}</span>
            </div>

            <div class="gp-hero-kpi" data-tone="{{ RateHealth::tone($rate) }}">
                <span class="gp-hero-kpi-label">Taux de recouvrement</span>
                <span class="gp-hero-kpi-value">{{ number_format($rate, 1, ',', ' ') }}&nbsp;%</span>
                <span class="gp-hero-kpi-meta">{{ RateHealth::label($rate) }}</span>
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
                    @php $rateClass = $data['collection_rate'] >= 70 ? 'success' : ($data['collection_rate'] >= 50 ? 'warning' : 'danger'); @endphp
                    <tr>
                        <td>
                            <div class="cell-name">
                                <div class="cell-icon blue">
                                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 21h16.5M4.5 3h15M5.25 3v18m13.5-18v18M9 6.75h1.5m-1.5 3h1.5m-1.5 3h1.5m3-6H15m-1.5 3H15m-1.5 3H15M9 21v-3.375c0-.621.504-1.125 1.125-1.125h3.75c.621 0 1.125.504 1.125 1.125V21" /></svg>
                                </div>
                                {{ $data['tenant_name'] }}
                            </div>
                        </td>
                        <td>{{ FcfaFormatter::full((float) $data['revenue_expected']) }}</td>
                        <td class="cell-green">{{ FcfaFormatter::full((float) $data['revenue_collected']) }}</td>
                        <td class="{{ ($data['outstanding'] ?? 0) > 0 ? 'cell-red' : '' }}">{{ FcfaFormatter::full((float) ($data['outstanding'] ?? 0)) }}</td>
                        <td style="text-align:center"><span class="gp-rate-badge {{ $rateClass }}">{{ $data['collection_rate'] }}%</span></td>
                        <td style="text-align:center">
                            <div class="gp-progress-track">
                                <div class="gp-progress-bar {{ $rateClass }}" style="width: {{ min($data['collection_rate'], 100) }}%"></div>
                            </div>
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
                            TOTAL GROUPE
                        </div>
                    </td>
                    <td>{{ FcfaFormatter::full((float) $totals['expected']) }}</td>
                    <td class="cell-green">{{ FcfaFormatter::full((float) $totals['collected']) }}</td>
                    <td>{{ FcfaFormatter::full((float) $totals['outstanding']) }}</td>
                    <td style="text-align:center"><span class="gp-rate-badge primary">{{ $totals['rate'] }}%</span></td>
                    <td style="text-align:center">
                        <div class="gp-progress-track">
                            <div class="gp-progress-bar success" style="width: {{ min($totals['rate'], 100) }}%; background: linear-gradient(90deg, var(--gp-primary), #5e91de);"></div>
                        </div>
                    </td>
                </tr>
            </tfoot>
        </table>
    </div>
</x-filament-panels::page>
