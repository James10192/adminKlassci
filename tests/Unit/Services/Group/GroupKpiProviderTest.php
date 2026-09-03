<?php

use App\Contracts\Group\GroupKpiProviderInterface;
use App\Models\Tenant;
use App\Services\Group\GroupKpiProvider;
use App\Support\EtatMesure;

it('binds the interface to the concrete provider via GroupServiceProvider', function () {
    $resolved = app(GroupKpiProviderInterface::class);

    expect($resolved)->toBeInstanceOf(GroupKpiProvider::class);
});

/**
 * Un etablissement jamais releve : la maitresse ne sait rien de lui.
 *
 * `academic_year` valait 'N/A' — une chaine fabriquee, que la vue affichait
 * telle quelle a cote du nom de l'ecole. Elle vaut null : on ne connait pas
 * l'annee, on ne l'invente pas.
 */
it('emptyKpis ne fabrique aucun chiffre quand rien n a jamais ete releve', function () {
    $tenant = new Tenant([
        'code' => 'foo',
        'name' => 'Foo Academy',
        'status' => 'active',
        'plan' => 'essentiel',
    ]);
    $tenant->id = 42;

    $empty = app(GroupKpiProvider::class)->emptyKpis($tenant);

    expect($empty)
        ->toHaveKey('tenant_id', 42)
        ->toHaveKey('tenant_code', 'foo')
        ->toHaveKey('tenant_name', 'Foo Academy')
        ->toHaveKey('students', 0)
        ->toHaveKey('inscriptions', 0)
        ->toHaveKey('revenue_expected', 0)
        ->toHaveKey('revenue_collected', 0)
        ->toHaveKey('collection_rate', 0)
        ->toHaveKey('staff', 0)
        ->toHaveKey('attendance_rate', 0)
        ->toHaveKey('academic_year', null)
        ->toHaveKey('status', 'active')
        ->toHaveKey('plan', 'essentiel')
        ->toHaveKey('error', true);

    // Les quatre familles sont declarees non mesurees. Sans ces drapeaux, les
    // zeros ci-dessus se lisent comme une ecole vide.
    expect($empty)
        ->toHaveKey('etat_effectifs', EtatMesure::NON_MESURE)
        ->toHaveKey('etat_personnel', EtatMesure::NON_MESURE)
        ->toHaveKey('etat_finances', EtatMesure::NON_MESURE)
        ->toHaveKey('etat_assiduite', EtatMesure::NON_MESURE)
        ->toHaveKey('motif', EtatMesure::MOTIF_INJOIGNABLE)
        ->toHaveKey('derniere_nouvelle_at', null);
});

/**
 * Le repli sur klassci_master serait une regression, pas une amelioration.
 *
 * La maitresse tient `current_students`, `current_staff`,
 * `current_inscriptions_per_year` — et l'idee d'y retomber pour afficher un
 * « dernier releve » est tentante. Elle est fausse : ces colonnes comptent
 * d'autres populations que les indicateurs du portail. `current_students`
 * compte les etudiants AYANT UN COMPTE plateforme ; le KPI compte les
 * inscriptions actives de l'annee. TenantConnectionManager avertit lui-meme que
 * les deux divergent fortement.
 *
 * Afficher 620 sous « Etudiants inscrits » quand le KPI en mesurerait 300
 * remplacerait un zero VISIBLE par un chiffre FAUX et invisible. Ce test fige
 * ce refus : il echouera si quelqu'un rebranche le repli.
 */
it('ne retombe jamais sur les compteurs de la maitresse, qui mesurent autre chose', function () {
    $tenant = new Tenant([
        'code' => 'foo',
        'name' => 'Foo Academy',
        'status' => 'active',
        'plan' => 'essentiel',
        'current_students' => 1236,
        'current_staff' => 58,
        'current_inscriptions_per_year' => 1240,
        'stats_measured_at' => now()->subMinutes(47),
    ]);
    $tenant->id = 42;

    $empty = app(GroupKpiProvider::class)->emptyKpis($tenant);

    expect($empty['students'])->toBe(0);
    expect($empty['staff'])->toBe(0);
    expect($empty['inscriptions'])->toBe(0);
    expect($empty['etat_effectifs'])->toBe(EtatMesure::NON_MESURE);
    expect($empty['etat_personnel'])->toBe(EtatMesure::NON_MESURE);

    // La date du dernier passage est conservee — mais elle ne date aucun
    // chiffre : elle dit depuis quand la maitresse est sans nouvelles.
    expect($empty['derniere_nouvelle_at'])->not->toBeNull();
});

/**
 * Une base qui repond sans annee universitaire courante n'est pas en panne.
 * Les deux cas retournaient le meme zero et le meme motif.
 */
it('distingue une ecole sans annee courante d une base injoignable', function () {
    $tenant = new Tenant(['code' => 'foo', 'name' => 'Foo Academy', 'status' => 'active', 'plan' => 'essentiel']);
    $tenant->id = 42;

    $sansAnnee = app(GroupKpiProvider::class)->emptyKpis($tenant, EtatMesure::MOTIF_SANS_ANNEE);

    expect($sansAnnee['motif'])->toBe(EtatMesure::MOTIF_SANS_ANNEE);
    expect(EtatMesure::badge($sansAnnee['etat_finances'], $sansAnnee['motif']))
        ->toBe('Année non configurée');
});

it('implements GroupKpiProviderInterface', function () {
    expect(app(GroupKpiProvider::class))
        ->toBeInstanceOf(GroupKpiProviderInterface::class);
});
