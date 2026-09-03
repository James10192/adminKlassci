@php use App\Support\EtatMesure; @endphp

@php
    /**
     * Le hero des rapports programmés.
     *
     * Cette page était la seule du portail à ne pas en avoir : elle affichait
     * le titre Filament brut au-dessus d'un tableau vide, sur fond blanc, là
     * où les sept autres écrans ouvrent sur le bandeau bleu du groupe. Elle
     * donnait le sentiment d'une page inachevée collée à un produit fini.
     *
     * Ses trois cartouches ne mesurent rien d'un établissement : ils comptent
     * des envois configurés dans klassci_master. Ils ne peuvent donc pas être
     * « non mesurés » — mais ils peuvent être vides, et le disent alors.
     */
    $actifs = (int) ($context['actifs'] ?? 0);
    $total = (int) ($context['total'] ?? 0);
    $destinataires = (int) ($context['destinataires'] ?? 0);
    $dernierEnvoi = $context['dernier_envoi'] ?? null;
@endphp

<x-group-hero
    title="Rapports programmés"
    subtitle="Recevez vos états par e-mail, sans ouvrir le portail"
    icon-path="M12 6v6h4.5m4.5 0a9 9 0 11-18 0 9 9 0 0118 0z"
>
    <x-slot:badges>
        <span class="gp-hero-chip">
            {{ $total }} envoi{{ $total > 1 ? 's' : '' }} configuré{{ $total > 1 ? 's' : '' }}
        </span>
    </x-slot:badges>

    {{-- `getHeader()` remplace TOUT l'en-tete, actions Filament comprises : sans
         ce bouton, la page n'offrait plus aucun moyen de programmer un envoi,
         juste sous un texte qui invite a le faire. --}}
    <x-slot:actions>
        <a href="{{ \App\Filament\Group\Resources\ReportScheduleResource::getUrl('create') }}"
           class="gp-hero-action">
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15"/>
            </svg>
            Programmer un envoi
        </a>
    </x-slot:actions>

    <x-slot:kpis>
        <div class="gp-hero-kpi" @if($actifs === 0) data-tone="inconnu" @endif>
            <span class="gp-hero-kpi-label">Envois actifs</span>
            <span class="gp-hero-kpi-value">{{ $actifs > 0 ? $actifs : EtatMesure::TIRET }}</span>
            <span class="gp-hero-kpi-meta">
                {{ $actifs > 0
                    ? 'partiront automatiquement'
                    : 'aucun envoi actif pour l\'instant' }}
            </span>
        </div>

        <div class="gp-hero-kpi" @if($destinataires === 0) data-tone="inconnu" @endif>
            <span class="gp-hero-kpi-label">Destinataires</span>
            <span class="gp-hero-kpi-value">{{ $destinataires > 0 ? $destinataires : EtatMesure::TIRET }}</span>
            <span class="gp-hero-kpi-meta">
                {{ $destinataires > 0
                    ? 'membre' . ($destinataires > 1 ? 's' : '') . ' du groupe'
                    : 'aucun destinataire' }}
            </span>
        </div>

        <div class="gp-hero-kpi" @if($dernierEnvoi === null) data-tone="inconnu" @endif>
            <span class="gp-hero-kpi-label">Dernier envoi</span>
            <span class="gp-hero-kpi-value" style="font-size: 1.15rem;">
                {{ $dernierEnvoi ?? EtatMesure::TIRET }}
            </span>
            <span class="gp-hero-kpi-meta">
                {{ $dernierEnvoi !== null ? 'dernier état parti par e-mail' : 'aucun envoi effectué' }}
            </span>
        </div>
    </x-slot:kpis>
</x-group-hero>
