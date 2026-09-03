@php use App\Support\EtatMesure; @endphp
<x-filament-widgets::widget>
    <div class="gp-cards-grid">
        @forelse($this->getEstablishments() as $code => $data)
            @php
                $etatEffectifs = $data['etat_effectifs'] ?? EtatMesure::MESURE;
                $etatPersonnel = $data['etat_personnel'] ?? EtatMesure::MESURE;
                $etatFinances  = $data['etat_finances'] ?? EtatMesure::MESURE;
                $motif = $data['motif'] ?? null;

                $financesMesurees = EtatMesure::estMesure($etatFinances);
                $rate = $data['collection_rate'] ?? 0;
                $rateClass = $financesMesurees
                    ? \App\Support\RateHealth::tone((float) $rate)
                    : 'inconnu';

                // Depuis quand la maitresse a eu des nouvelles de cette ecole.
                // Cette date ne DATE aucun chiffre de la carte — elle dit
                // seulement quand le dernier releve a eu lieu.
                $derniereNouvelle = EtatMesure::age(
                    ($data['derniere_nouvelle_at'] ?? null)
                        ? \Illuminate\Support\Carbon::parse($data['derniere_nouvelle_at'])
                        : null
                );

                // Une carte n'est « en defaut » que si RIEN n'y est mesure.
                // Une ecole injoignable dont la maitresse tient les effectifs
                // reste une carte qui dit quelque chose de vrai.
                $etatCarte = EtatMesure::estMesure($etatFinances)
                    ? EtatMesure::MESURE
                    : $etatEffectifs;
            @endphp
            <div class="gp-card">
                <div class="gp-card-accent"></div>
                <div class="gp-card-body">
                    {{-- Header --}}
                    <div class="gp-card-header">
                        <div class="gp-card-header-left">
                            <div class="gp-card-icon">
                                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 21h16.5M4.5 3h15M5.25 3v18m13.5-18v18M9 6.75h1.5m-1.5 3h1.5m-1.5 3h1.5m3-6H15m-1.5 3H15m-1.5 3H15M9 21v-3.375c0-.621.504-1.125 1.125-1.125h3.75c.621 0 1.125.504 1.125 1.125V21" /></svg>
                            </div>
                            <div>
                                <div class="gp-card-name">{{ $data['tenant_name'] }}</div>
                                @php
                                    // « islg-rostan · — » : un separateur suivi
                                    // d'un tiret est du bruit. Sans annee connue,
                                    // le code se suffit ; le badge de la carte dit
                                    // deja qu'elle n'est pas mesuree.
                                    $anneeCarte = $data['academic_year'] ?? null;
                                    $anneeCarte = ($anneeCarte === null || $anneeCarte === '' || $anneeCarte === 'N/A')
                                        ? null
                                        : $anneeCarte;
                                @endphp
                                <div class="gp-card-code">{{ $code }}@if($anneeCarte) &middot; {{ $anneeCarte }}@endif</div>
                            </div>
                        </div>
                        @if(EtatMesure::estMesure($etatCarte))
                            <span class="gp-status gp-status-active">{{ ucfirst($data['status'] ?? 'active') }}</span>
                        @else
                            <span class="gp-status gp-status-{{ $etatCarte === EtatMesure::RELEVE ? 'releve' : 'inconnu' }}"
                                  title="{{ ucfirst(EtatMesure::libelleMotif($motif)) }}{{ $derniereNouvelle ? ' — dernières nouvelles ' . $derniereNouvelle : '' }}">
                                {{ EtatMesure::badge($etatCarte, $motif) }}
                            </span>
                        @endif
                    </div>

                    {{-- KPIs --}}
                    <div class="gp-kpi-grid">
                        <div class="gp-kpi-box">
                            <div class="gp-kpi-label">
                                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M4.26 10.147a60.436 60.436 0 00-.491 6.347A48.627 48.627 0 0112 20.904a48.627 48.627 0 018.232-4.41 60.46 60.46 0 00-.491-6.347m-15.482 0a50.57 50.57 0 00-2.658-.813A59.905 59.905 0 0112 3.493a59.902 59.902 0 0110.399 5.84c-.896.248-1.783.52-2.658.814m-15.482 0A50.697 50.697 0 0112 13.489a50.702 50.702 0 017.74-3.342" /></svg>
                                Étudiants
                            </div>
                            @if(EtatMesure::aUneValeur($etatEffectifs))
                                <div class="gp-kpi-value">{{ number_format($data['students'] ?? 0, 0, ',', ' ') }}</div>
                            @else
                                <div class="gp-kpi-value gp-kpi-value--inconnu">{{ EtatMesure::TIRET }}</div>
                                <div class="gp-kpi-note">non mesuré</div>
                            @endif
                        </div>
                        <div class="gp-kpi-box">
                            <div class="gp-kpi-label">
                                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M18 18.72a9.094 9.094 0 003.741-.479 3 3 0 00-4.682-2.72m.94 3.198l.001.031c0 .225-.012.447-.037.666A11.944 11.944 0 0112 21c-2.17 0-4.207-.576-5.963-1.584A6.062 6.062 0 016 18.719m12 0a5.971 5.971 0 00-.941-3.197m0 0A5.995 5.995 0 0012 12.75a5.995 5.995 0 00-5.058 2.772m0 0a3 3 0 00-4.681 2.72 8.986 8.986 0 003.74.477m.94-3.197a5.971 5.971 0 00-.94 3.197M15 6.75a3 3 0 11-6 0 3 3 0 016 0zm6 3a2.25 2.25 0 11-4.5 0 2.25 2.25 0 014.5 0zm-13.5 0a2.25 2.25 0 11-4.5 0 2.25 2.25 0 014.5 0z" /></svg>
                                Personnel
                            </div>
                            @if(EtatMesure::aUneValeur($etatPersonnel))
                                <div class="gp-kpi-value">{{ $data['staff'] ?? 0 }}</div>
                            @else
                                <div class="gp-kpi-value gp-kpi-value--inconnu">{{ EtatMesure::TIRET }}</div>
                                <div class="gp-kpi-note">non mesuré</div>
                            @endif
                        </div>
                    </div>

                    {{-- Progress --}}
                    <div class="gp-progress-section">
                        <div class="gp-progress-header">
                            <span class="gp-progress-label">Recouvrement</span>
                            <span class="gp-progress-value {{ $rateClass }}">
                                {{ $financesMesurees ? $rate . '%' : EtatMesure::TIRET }}
                            </span>
                        </div>
                        @if($financesMesurees)
                            <div class="gp-progress-track">
                                <div class="gp-progress-bar {{ $rateClass }}" style="width: {{ min($rate, 100) }}%"></div>
                            </div>
                        @else
                            {{-- Pas de barre a zero : une jauge vide se lit comme
                                 un recouvrement nul, ce qui n'est pas ce qu'on sait. --}}
                            <div class="gp-progress-track gp-progress-track--inconnu" title="{{ ucfirst(EtatMesure::libelleMotif($motif)) }}"></div>
                        @endif
                    </div>

                    {{-- Revenue --}}
                    {{-- Le montant passait bien au gris, mais la pastille restait
                         VERTE autour de lui : fond vert d'eau, bordure verte,
                         libellé vert. Un « Encaissé — » sur pastille verte se lit
                         encore comme une bonne nouvelle. La couleur porte le sens
                         autant que le chiffre. --}}
                    <div class="gp-revenue {{ $financesMesurees ? '' : 'gp-revenue--inconnu' }}">
                        <span class="gp-revenue-label">Encaissé</span>
                        <span class="gp-revenue-value {{ $financesMesurees ? '' : 'gp-revenue-value--inconnu' }}">
                            {{ $financesMesurees
                                ? number_format(($data['revenue_collected'] ?? 0) / 1000000, 1, ',', ' ') . 'M FCFA'
                                : EtatMesure::TIRET }}
                        </span>
                    </div>

                    {{-- Action --}}
                    {{-- Forme BLOC, pas la forme courte `@@php(...)` : ce fichier
                         contient aussi des blocs `@@php ... @@endphp`, et le regex
                         `storePhpBlocks` de Blade ne distingue pas les deux. Le
                         jour ou un `@@endphp` apparait plus bas, il avalerait tout
                         le balisage intermediaire, qui ne serait plus compile —
                         incident documente cote KLASSCIv2. --}}
                    @php $ssoUrl = $this->getSsoUrl($code); @endphp
                    @if($ssoUrl)
                        <a href="{{ $ssoUrl }}" target="_blank" class="gp-card-action" rel="noopener">
                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M13.5 6H5.25A2.25 2.25 0 003 8.25v10.5A2.25 2.25 0 005.25 21h10.5A2.25 2.25 0 0018 18.75V10.5m-10.5 6L21 3m0 0h-5.25M21 3v5.25" /></svg>
                            Ouvrir l'établissement
                        </a>
                    @else
                        <span class="gp-card-action gp-card-action--disabled" title="Connexion SSO indisponible">
                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M13.5 6H5.25A2.25 2.25 0 003 8.25v10.5A2.25 2.25 0 005.25 21h10.5A2.25 2.25 0 0018 18.75V10.5m-10.5 6L21 3m0 0h-5.25M21 3v5.25" /></svg>
                            Ouvrir l'établissement
                        </span>
                    @endif
                </div>
            </div>
        @empty
            <div class="gp-empty">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 21h19.5m-18-18v18m10.5-18v18m6-13.5V21M6.75 6.75h.75m-.75 3h.75m-.75 3h.75m3-6h.75m-.75 3h.75m-.75 3h.75M6.75 21v-3.375c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125V21M3 3h12m-.75 4.5H21m-3.75 3h.008v.008h-.008v-.008zm0 3h.008v.008h-.008v-.008zm0 3h.008v.008h-.008v-.008z" /></svg>
                <p class="gp-empty-title">Aucun établissement</p>
                <p class="gp-empty-text">Aucun établissement n'est encore associé à ce groupe.</p>
            </div>
        @endforelse
    </div>
</x-filament-widgets::widget>
