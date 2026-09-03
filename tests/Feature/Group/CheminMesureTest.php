<?php

use App\Support\EtatMesure;

/**
 * Ce que les écrans affichent quand les bases RÉPONDENT.
 *
 * Tout ce chantier corrige le chemin dégradé — et aucune base MySQL ne tourne
 * en environnement de développement, donc les captures d'écran n'ont jamais
 * montré que des tirets. Le risque est réel et connu : à force de border le cas
 * « non mesuré », on casse le cas nominal sans jamais le voir.
 *
 * Ces tests rendent les mêmes partials avec des mesures présentes et vérifient
 * qu'ils affichent bien des chiffres, les bonnes couleurs, et aucune mention
 * d'absence.
 */
it('le hero établissements affiche des chiffres et un ton sain quand tout est mesuré', function () {
    $html = view('filament.group.partials.establishments-hero', [
        'context' => [
            'total_students' => 2140,
            'total_staff' => 58,
            'establishment_count' => 4,
            'avg_rate' => 82.4,
            'etat_effectifs' => EtatMesure::MESURE,
            'etat_personnel' => EtatMesure::MESURE,
            'etat_finances' => EtatMesure::MESURE,
            'mention_effectifs' => null,
            'mention_personnel' => null,
            'mention_finances' => null,
        ],
    ])->render();

    expect($html)->toContain('2 140');
    expect($html)->toContain('58');
    expect($html)->toContain('82,4');
    expect($html)->toContain('data-tone="success"');
    expect($html)->toContain('sain');

    // Aucun vocabulaire d'absence quand tout a répondu.
    expect($html)->not->toContain(EtatMesure::absenceGroupe());
    expect($html)->not->toContain('data-tone="inconnu"');
});

it('le hero établissements vire au rouge sur un recouvrement réellement bas', function () {
    // Le rouge doit rester possible : ce chantier retire le rouge de l'INCONNU,
    // pas le rouge du risque mesuré.
    $html = view('filament.group.partials.establishments-hero', [
        'context' => [
            'total_students' => 800, 'total_staff' => 20,
            'establishment_count' => 2, 'avg_rate' => 31.0,
            'etat_effectifs' => EtatMesure::MESURE,
            'etat_personnel' => EtatMesure::MESURE,
            'etat_finances' => EtatMesure::MESURE,
            'mention_effectifs' => null, 'mention_personnel' => null, 'mention_finances' => null,
        ],
    ])->render();

    expect($html)->toContain('data-tone="danger"');
    expect($html)->toContain('critique');
    expect($html)->toContain('31,0');
});

it('la fiche établissement affiche ses chiffres quand sa base a répondu', function () {
    $tenant = new \App\Models\Tenant();
    $tenant->name = 'ISLG Rostan';
    $tenant->code = 'islg-rostan';
    $tenant->plan = 'elite';
    $tenant->status = 'active';
    $tenant->subdomain = 'islg-rostan';

    $html = view('filament.group.partials.establishment-view-hero', [
        'tenant' => $tenant,
        'kpis' => [
            'students' => 1236,
            'staff' => 47,
            'collection_rate' => 91.2,
            'academic_year' => '2025-2026',
            'etat_effectifs' => EtatMesure::MESURE,
            'etat_personnel' => EtatMesure::MESURE,
            'etat_finances' => EtatMesure::MESURE,
            'motif' => null,
        ],
    ])->render();

    expect($html)->toContain('1 236');
    expect($html)->toContain('47');
    expect($html)->toContain('91,2');
    expect($html)->toContain('2025-2026');
    expect($html)->toContain('data-tone="success"');
    expect($html)->not->toContain("la base de l'établissement n'a pas répondu");
});

it('une école mesurée sur ses effectifs et muette sur sa paie n\'affiche qu\'un tiret sur la paie', function () {
    // L'état appartient à chaque FAMILLE, pas à l'établissement : un seul état
    // par école serait déjà un mensonge d'un cran.
    $tenant = new \App\Models\Tenant();
    $tenant->name = 'ISLG Rostan';
    $tenant->code = 'islg-rostan';
    $tenant->plan = 'elite';
    $tenant->status = 'active';
    $tenant->subdomain = 'islg-rostan';

    $html = view('filament.group.partials.establishment-view-hero', [
        'tenant' => $tenant,
        'kpis' => [
            'students' => 1236, 'staff' => 47, 'collection_rate' => 0,
            'academic_year' => '2025-2026',
            'etat_effectifs' => EtatMesure::MESURE,
            'etat_personnel' => EtatMesure::MESURE,
            'etat_finances' => EtatMesure::NON_MESURE,
            'motif' => EtatMesure::MOTIF_INJOIGNABLE,
        ],
    ])->render();

    expect($html)->toContain('1 236');           // les effectifs restent affichés
    expect($html)->toContain(EtatMesure::TIRET); // la finance, non
    expect($html)->toContain('data-tone="inconnu"');
    expect($html)->not->toContain('data-tone="danger"');
    expect($html)->not->toContain('0,0');
});
