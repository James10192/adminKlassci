<?php

/**
 * Le taux de recouvrement du groupe se pondère par les montants.
 *
 * Le Benchmarking faisait la moyenne arithmétique des taux de chaque école,
 * là où le tableau de bord divise l'encaissé du groupe par son attendu. Les
 * deux écrans affichaient donc deux taux différents pour le même groupe, le
 * même jour — et c'est l'écran de comparaison, celui qu'on ouvre justement
 * pour arbitrer, qui donnait le chiffre flatteur.
 */
it('diverge assez pour changer une décision — la moyenne simple est écartée', function () {
    // Deux écoles de poids très inégal : une petite école à jour, une grosse
    // école en difficulté.
    $ecoles = [
        ['attendu' => 100_000.0, 'encaisse' => 100_000.0],   // 100 %
        ['attendu' => 1_000_000.0, 'encaisse' => 100_000.0], //  10 %
    ];

    $attendu = array_sum(array_column($ecoles, 'attendu'));
    $encaisse = array_sum(array_column($ecoles, 'encaisse'));

    $pondere = round(($encaisse / $attendu) * 100, 1);
    $moyenneSimple = round(array_sum(array_map(
        static fn (array $e): float => ($e['encaisse'] / $e['attendu']) * 100,
        $ecoles,
    )) / count($ecoles), 1);

    expect($pondere)->toBe(18.2);
    expect($moyenneSimple)->toBe(55.0);

    // 37 points d'écart : le groupe passe de « critique » à « à surveiller »
    // selon la formule choisie. Ce n'est pas une nuance d'arrondi.
    expect(abs($moyenneSimple - $pondere))->toBeGreaterThan(30.0);
});

it('le Benchmarking pondère par les montants, comme le tableau de bord', function () {
    $vue = file_get_contents(resource_path('views/filament/group/pages/benchmarking.blade.php'));

    // Le dénominateur doit être un montant cumulé, pas un compte d'écoles.
    expect($vue)->toContain('$totalRevenueCollected / $totalRevenueExpected');

    // L'ancienne moyenne de taux ne doit pas revenir.
    expect($vue)->not->toContain('$rateSum');

    // Et sans attendu, pas de taux : le tiret, jamais un 0 % qui accuse.
    expect($vue)->toContain('$tauxMesurable');
});

it('le tableau de bord garde la même règle', function () {
    $service = file_get_contents(app_path('Services/Group/GroupKpiProvider.php'));

    expect($service)->toContain("\$totals['total_revenue_expected'] > 0");
});
