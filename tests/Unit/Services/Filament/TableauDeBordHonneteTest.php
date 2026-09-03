<?php

use App\Filament\Widgets\TenantsByPlanChart;

/**
 * Le tableau de bord du fondateur portait sept sparklines dont aucune ne
 * venait d'un relevé : des séries codées en dur, ou de l'arithmétique sur le
 * chiffre du jour maquillée en historique. Elles montaient toujours.
 *
 * Ce test empêche qu'elles reviennent. Il n'interdit pas les graphiques : il
 * interdit les séries écrites à la main. Une courbe alimentée par une requête
 * passe ; le jour où un relevé quotidien existera, elle sera la bienvenue.
 */
it('ne laisse aucune série écrite à la main dans les widgets du tableau de bord', function () {
    $coupables = [];

    foreach (glob(app_path('Filament/Widgets/*.php')) as $fichier) {
        $source = file_get_contents($fichier);

        // ->chart([...]) : une serie litterale, donc inventee.
        if (preg_match('/->chart\(\s*\[/', $source) === 1) {
            $coupables[] = basename($fichier) . ' : série littérale';
        }

        // Fabrique de « progression plausible » a partir de la valeur du jour.
        if (str_contains($source, 'buildProgressionChart')) {
            $coupables[] = basename($fichier) . ' : progression reconstituée';
        }

        // array_fill produit une ligne plate presentee comme un historique.
        if (preg_match('/->chart\(\s*array_fill/', $source) === 1) {
            $coupables[] = basename($fichier) . ' : série constante';
        }
    }

    expect($coupables)->toBe([]);
});

it('coupe les axes du graphique en anneau', function () {
    // Filament pose des échelles pensées pour les histogrammes ; sur un anneau
    // elles dessinent un axe de 0 à 1 qui ne mesure rien.
    $methode = new ReflectionMethod(TenantsByPlanChart::class, 'getOptions');
    $methode->setAccessible(true);

    $options = $methode->invoke(new TenantsByPlanChart());

    expect($options['scales']['x']['display'])->toBeFalse()
        ->and($options['scales']['y']['display'])->toBeFalse();
});
