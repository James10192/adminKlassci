<x-filament-panels::page>
    @php
        $financials = $this->getFinancials();
        $totals = $this->getTotals();
    @endphp

    {{-- Summary cards --}}
    <div class="gp-summary-grid">
        <div class="gp-summary-card blue">
            <div class="gp-summary-label blue">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 18.75a60.07 60.07 0 0115.797 2.101c.727.198 1.453-.342 1.453-1.096V18.75M3.75 4.5v.75A.75.75 0 013 6h-.75m0 0v-.375c0-.621.504-1.125 1.125-1.125H20.25M2.25 6v9m18-10.5v.75c0 .414.336.75.75.75h.75m-1.5-1.5h.375c.621 0 1.125.504 1.125 1.125v9.75c0 .621-.504 1.125-1.125 1.125h-.375m1.5-1.5H21a.75.75 0 00-.75.75v.75m0 0H3.75m0 0h-.375a1.125 1.125 0 01-1.125-1.125V15m1.5 1.5v-.75A.75.75 0 003 15h-.75M15 10.5a3 3 0 11-6 0 3 3 0 016 0zm3 0h.008v.008H18V10.5zm-12 0h.008v.008H6V10.5z" /></svg>
                Revenus attendus
            </div>
            <div class="gp-summary-amount">{{ number_format($totals['expected'], 0, ',', ' ') }}</div>
            <div class="gp-summary-unit">FCFA</div>
        </div>

        <div class="gp-summary-card green">
            <div class="gp-summary-label green">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
                Encaissés
            </div>
            <div class="gp-summary-amount green">{{ number_format($totals['collected'], 0, ',', ' ') }}</div>
            <div class="gp-summary-unit">FCFA</div>
        </div>

        <div class="gp-summary-card red">
            <div class="gp-summary-label red">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126zM12 15.75h.007v.008H12v-.008z" /></svg>
                {{ $totals['outstanding'] > 0 ? 'Impayés' : 'Surplus' }}
            </div>
            <div class="gp-summary-amount {{ $totals['outstanding'] > 0 ? 'red' : 'green' }}">
                {{ number_format(max($totals['outstanding'], $totals['surplus'] ?? 0), 0, ',', ' ') }}
            </div>
            <div class="gp-summary-unit">FCFA</div>
        </div>

        <div class="gp-summary-card blue">
            <div class="gp-summary-label blue">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M3 13.125C3 12.504 3.504 12 4.125 12h2.25c.621 0 1.125.504 1.125 1.125v6.75C7.5 20.496 6.996 21 6.375 21h-2.25A1.125 1.125 0 013 19.875v-6.75zM9.75 8.625c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125v11.25c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 01-1.125-1.125V8.625zM16.5 4.125c0-.621.504-1.125 1.125-1.125h2.25C20.496 3 21 3.504 21 4.125v15.75c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 01-1.125-1.125V4.125z" /></svg>
                Taux de recouvrement
            </div>
            <div class="gp-summary-amount">{{ $totals['rate'] }}%</div>
            <div class="gp-progress-track" style="margin-top: 0.5rem;">
                <div class="gp-progress-bar {{ $totals['rate'] >= 70 ? 'success' : ($totals['rate'] >= 50 ? 'warning' : 'danger') }}" style="width: {{ min($totals['rate'], 100) }}%"></div>
            </div>
        </div>
    </div>

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
                        <td>{{ number_format($data['revenue_expected'], 0, ',', ' ') }}</td>
                        <td class="cell-green">{{ number_format($data['revenue_collected'], 0, ',', ' ') }}</td>
                        <td class="{{ ($data['outstanding'] ?? 0) > 0 ? 'cell-red' : '' }}">{{ number_format($data['outstanding'] ?? 0, 0, ',', ' ') }}</td>
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
                    <td>{{ number_format($totals['expected'], 0, ',', ' ') }}</td>
                    <td class="cell-green">{{ number_format($totals['collected'], 0, ',', ' ') }}</td>
                    <td>{{ number_format($totals['outstanding'], 0, ',', ' ') }}</td>
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
