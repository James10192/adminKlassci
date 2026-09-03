{{--
    Un graphique de groupe, ou la raison pour laquelle il n'y en a pas.

    Les deux graphes du tableau de bord tracaient les etablissements
    injoignables comme des valeurs nulles : quatre barres a plat, ou quatre
    parts de camembert vides. Ils ne les tracent plus — mais un ChartWidget
    prive de donnees rend alors un CANEVAS VIDE sous son titre, c'est-a-dire
    une carte blanche au bas du tableau de bord du fondateur.

    Un graphe ne sait pas dire « je ne sais pas ». Quand il n'a rien a
    dessiner, on affiche la phrase a sa place plutot que le vide.
--}}
@php
    $donnees = $this->getCachedData();
    $vide = empty($donnees['labels'] ?? []);
@endphp

@if (! $vide)
    @include('filament-widgets::chart-widget')
@else
    <x-filament-widgets::widget class="fi-wi-chart">
        <x-filament::section :heading="$this->getHeading()">
            <div class="gp-chart-vide">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M3 13.125C3 12.504 3.504 12 4.125 12h2.25c.621 0 1.125.504 1.125 1.125v6.75C7.5 20.496 6.996 21 6.375 21h-2.25A1.125 1.125 0 013 19.875v-6.75zM9.75 8.625c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125v11.25c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 01-1.125-1.125V8.625zM16.5 4.125c0-.621.504-1.125 1.125-1.125h2.25C20.496 3 21 3.504 21 4.125v15.75c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 01-1.125-1.125V4.125z" />
                </svg>
                <p class="gp-chart-vide-titre">Rien à comparer pour l'instant</p>
                <p class="gp-chart-vide-detail">{{ $this->getDescription() ?? "Aucune donnée mesurée sur ce périmètre." }}</p>
            </div>
        </x-filament::section>
    </x-filament-widgets::widget>
@endif
