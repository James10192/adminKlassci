@php
    $catalogue = $this->catalogue();
    $consolides = array_values(array_filter($catalogue, fn ($r) => ! $r['detail']));
    $details = array_values(array_filter($catalogue, fn ($r) => $r['detail']));
@endphp

<x-filament-panels::page>
    <x-group-hero
        title="Rapports"
        subtitle="Les états du groupe, en PDF pour la lecture et en tableur pour le travail"
        icon-path="M19.5 14.25v-2.625a3.375 3.375 0 0 0-3.375-3.375h-1.5A1.125 1.125 0 0 1 13.5 7.125v-1.5a3.375 3.375 0 0 0-3.375-3.375H8.25m2.25 0H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 0 0-9-9Z"
    />

    <form wire:submit.prevent>
        {{ $this->form }}
    </form>

    @foreach ([
        ['titre' => 'États consolidés', 'note' => "Une ligne par établissement. Ils se sortent sans cadrage particulier.", 'rapports' => $consolides],
        ['titre' => 'États de détail', 'note' => "Une ligne par paiement ou par étudiant. Le cadrage ci-dessus s'y applique, et il leur est nécessaire : sur un groupe entier et une année complète, ces états dépassent ce qu'un PDF peut porter. Le tableur, lui, encaisse bien davantage.", 'rapports' => $details],
    ] as $section)
        <section class="gp-rapports-section">
            <h2 class="gp-rapports-section-titre">{{ $section['titre'] }}</h2>
            <p class="gp-rapports-section-note">{{ $section['note'] }}</p>

            <div class="gp-rapports-grid">
                @foreach ($section['rapports'] as $rapport)
                    <div class="gp-rapport-carte">
                        <div>
                            <h3 class="gp-rapport-carte-titre">{{ $rapport['titre'] }}</h3>
                            <p class="gp-rapport-carte-resume">{{ $rapport['resume'] }}</p>
                        </div>

                        <div class="gp-rapport-carte-actions">
                            <x-filament::button
                                size="sm"
                                color="gray"
                                icon="heroicon-o-document-text"
                                wire:click="telechargerPdf('{{ $rapport['cle'] }}')"
                                wire:target="telechargerPdf('{{ $rapport['cle'] }}')"
                                wire:loading.attr="disabled"
                            >
                                PDF
                            </x-filament::button>

                            <x-filament::button
                                size="sm"
                                color="gray"
                                icon="heroicon-o-table-cells"
                                wire:click="telechargerExcel('{{ $rapport['cle'] }}')"
                                wire:target="telechargerExcel('{{ $rapport['cle'] }}')"
                                wire:loading.attr="disabled"
                            >
                                Excel
                            </x-filament::button>
                        </div>
                    </div>
                @endforeach
            </div>
        </section>
    @endforeach

    <x-filament-actions::modals />
</x-filament-panels::page>
