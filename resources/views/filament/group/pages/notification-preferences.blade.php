<x-filament-panels::page>
    <x-group-hero
        title="Préférences de notification"
        subtitle="Choisissez quelles alertes vous recevez, sur quel canal et à quelle fréquence"
        icon-path="M14.857 17.082a23.848 23.848 0 0 0 5.454-1.31A8.967 8.967 0 0 1 18 9.75V9A6 6 0 0 0 6 9v.75a8.967 8.967 0 0 1-2.312 6.022c1.733.64 3.56 1.085 5.455 1.31m5.714 0a24.255 24.255 0 0 1-5.714 0m5.714 0a3 3 0 1 1-5.714 0"
    >
        <x-slot:badges>
            <span class="gp-hero-chip">Portail groupe</span>
            <span class="gp-hero-chip">Emails — {{ auth('group')->user()->email }}</span>
        </x-slot:badges>
    </x-group-hero>

    <form wire:submit="save" class="gp-prefs-form">
        {{ $this->form }}

        <div class="gp-prefs-actions">
            <x-filament::button type="submit" color="primary" size="lg">
                Enregistrer mes préférences
            </x-filament::button>
        </div>
    </form>
</x-filament-panels::page>
