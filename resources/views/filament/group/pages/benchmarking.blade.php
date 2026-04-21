@php use App\Support\FcfaFormatter; use App\Support\RateHealth; @endphp
<x-filament-panels::page>
    @php
        $establishments = $this->getComparisonData();
        $enrollment = $this->getEnrollmentData();

        // Hero KPIs aggregated from comparison data (zero new service call).
        $totalInscriptions = 0;
        $totalStaff = 0;
        $totalRevenueCollected = 0.0;
        $rateSum = 0.0;
        $rateCount = 0;
        foreach ($establishments as $d) {
            $totalInscriptions += (int) ($d['inscriptions'] ?? 0);
            $totalStaff += (int) ($d['staff'] ?? 0);
            $totalRevenueCollected += (float) ($d['revenue_collected'] ?? 0);
            if (isset($d['collection_rate'])) {
                $rateSum += (float) $d['collection_rate'];
                $rateCount++;
            }
        }
        $avgRate = $rateCount > 0 ? $rateSum / $rateCount : 0;
        $avgRatio = $totalStaff > 0 ? round($totalInscriptions / $totalStaff, 1) : 0;
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
            <div class="gp-hero-kpi">
                <span class="gp-hero-kpi-label">Étudiants total</span>
                <span class="gp-hero-kpi-value">{{ FcfaFormatter::integer($totalInscriptions) }}</span>
                <span class="gp-hero-kpi-meta">cumul du groupe</span>
            </div>

            <div class="gp-hero-kpi">
                <span class="gp-hero-kpi-label">Personnel total</span>
                <span class="gp-hero-kpi-value">{{ FcfaFormatter::integer($totalStaff) }}</span>
                <span class="gp-hero-kpi-meta">ratio {{ $avgRatio }}:1</span>
            </div>

            <div class="gp-hero-kpi" data-tone="{{ RateHealth::tone($avgRate) }}">
                <span class="gp-hero-kpi-label">Taux moyen recouvrement</span>
                <span class="gp-hero-kpi-value">{{ number_format($avgRate, 1, ',', ' ') }}&nbsp;%</span>
                <span class="gp-hero-kpi-meta">{{ RateHealth::label($avgRate) }}</span>
            </div>

            <div class="gp-hero-kpi" data-tone="success">
                <span class="gp-hero-kpi-label">Encaissés cumulés</span>
                <span class="gp-hero-kpi-value">{{ FcfaFormatter::compact($totalRevenueCollected) }}</span>
                <span class="gp-hero-kpi-meta">FCFA cross-établissements</span>
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
                        <th>{{ $data['tenant_name'] }}</th>
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
                        <td class="cell-bold">{{ FcfaFormatter::full((float) ($data['inscriptions'] ?? 0)) }}</td>
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
                        <td>{{ $data['staff'] ?? 0 }}</td>
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
                        @php $ratio = ($data['staff'] ?? 0) > 0 ? round(($data['inscriptions'] ?? 0) / $data['staff'], 1) : 0; @endphp
                        <td>{{ $ratio }}:1</td>
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
                        @php $rateClass = ($data['collection_rate'] ?? 0) >= 70 ? 'success' : (($data['collection_rate'] ?? 0) >= 50 ? 'warning' : 'danger'); @endphp
                        <td><span class="gp-rate-badge {{ $rateClass }}">{{ $data['collection_rate'] ?? 0 }}%</span></td>
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
                        <td class="cell-bold" style="color: var(--gp-success)">{{ FcfaFormatter::millions((float) ($data['revenue_collected'] ?? 0)) }} M</td>
                    @endforeach
                </tr>
                <tr>
                    <td>
                        <div class="gp-scorecard-indicator">
                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12h3.75M9 15h3.75M9 18h3.75m3 .75H18a2.25 2.25 0 002.25-2.25V6.108c0-1.135-.845-2.098-1.976-2.192a48.424 48.424 0 00-1.123-.08m-5.801 0c-.065.21-.1.433-.1.664 0 .414.336.75.75.75h4.5a.75.75 0 00.75-.75 2.25 2.25 0 00-.1-.664m-5.8 0A2.251 2.251 0 0113.5 2.25H15c1.012 0 1.867.668 2.15 1.586m-5.8 0c-.376.023-.75.05-1.124.08C9.095 4.01 8.25 4.973 8.25 6.108V8.25m0 0H4.875c-.621 0-1.125.504-1.125 1.125v11.25c0 .621.504 1.125 1.125 1.125h9.75c.621 0 1.125-.504 1.125-1.125V9.375c0-.621-.504-1.125-1.125-1.125H8.25zM6.75 12h.008v.008H6.75V12zm0 3h.008v.008H6.75V15zm0 3h.008v.008H6.75V18z" /></svg>
                            Présences (30j)
                        </div>
                    </td>
                    @foreach($establishments as $data)
                        <td>{{ ($data['attendance_rate'] ?? 0) > 0 ? $data['attendance_rate'] . '%' : 'N/A' }}</td>
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
                        <div class="gp-filiere-empty">Aucune donnée</div>
                    @endforelse
                </div>
            </div>
        @endforeach
    </div>
</x-filament-panels::page>
