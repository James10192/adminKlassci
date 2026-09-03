<?php

use App\Filament\Group\Resources\EstablishmentResource\Pages\ListEstablishments;
use App\Filament\Group\Resources\EstablishmentResource\Pages\ViewEstablishment;

it('ListEstablishments declares a custom header + empty heading', function () {
    $reflection = new ReflectionClass(ListEstablishments::class);

    expect($reflection->hasMethod('getHeader'))->toBeTrue();
    expect($reflection->hasMethod('getHeading'))->toBeTrue();
    expect($reflection->hasMethod('buildHeroContext'))->toBeTrue();
});

it('ViewEstablishment declares a custom header + empty heading', function () {
    $reflection = new ReflectionClass(ViewEstablishment::class);

    expect($reflection->hasMethod('getHeader'))->toBeTrue();
    expect($reflection->hasMethod('getHeading'))->toBeTrue();
});

it('establishments-hero partial renders happy path', function () {
    $html = view('filament.group.partials.establishments-hero', [
        'context' => [
            'total_students' => 120,
            'total_staff' => 18,
            'establishment_count' => 2,
            'avg_rate' => 75.5,
        ],
    ])->render();

    expect($html)->toContain('Mes Établissements');
    expect($html)->toContain('120');
    expect($html)->toContain('75,5');
    expect($html)->toContain('data-tone="success"');
});

it('establishments-hero uses singular form when count = 1', function () {
    $html = view('filament.group.partials.establishments-hero', [
        'context' => [
            'total_students' => 12,
            'total_staff' => 8,
            'establishment_count' => 1,
            'avg_rate' => 50.0,
        ],
    ])->render();

    expect($html)->toContain('1 établissement');
    expect($html)->not->toContain('1 établissements');
});

it('establishment-view-hero partial renders happy path', function () {
    $tenant = new \App\Models\Tenant();
    $tenant->name = 'ROSTAN Abidjan';
    $tenant->code = 'rostan';
    $tenant->plan = 'professional';
    $tenant->status = 'active';
    $tenant->subdomain = 'rostan';

    $html = view('filament.group.partials.establishment-view-hero', [
        'tenant' => $tenant,
        'kpis' => [
            'students' => 12,
            'staff' => 8,
            'collection_rate' => 9.9,
            'academic_year' => '2025-2026',
        ],
    ])->render();

    expect($html)->toContain('ROSTAN Abidjan');
    expect($html)->toContain('rostan · Plan Professional');
    expect($html)->toContain('Actif');
    expect($html)->toContain('data-tone="danger"');
    expect($html)->toContain('rostan.klassci.com');
});

it('establishment-view-hero omits the SSO button when tenant is suspended', function () {
    $tenant = new \App\Models\Tenant();
    $tenant->name = 'Test';
    $tenant->code = 'test';
    $tenant->plan = 'free';
    $tenant->status = 'suspended';
    $tenant->subdomain = 'test';

    $html = view('filament.group.partials.establishment-view-hero', [
        'tenant' => $tenant,
        'kpis' => [],
    ])->render();

    expect($html)->toContain('Suspendu');
    expect($html)->not->toContain("Ouvrir l'établissement");
    expect($html)->not->toContain('https://test.klassci.com');
});

it('le hero établissements refuse le zéro et le rouge quand rien n\'est mesuré', function () {
    // Cet écran affichait « 0 étudiant », « 0 personnel » et surtout
    // « Recouvrement moyen 0,0 % — critique » dans une tuile ROUGE alors
    // qu'aucune base n'avait répondu. Un fondateur y lisait un groupe en
    // détresse financière là où il n'y avait qu'une panne de connexion.
    $html = view('filament.group.partials.establishments-hero', [
        'context' => [
            'total_students' => 0,
            'total_staff' => 0,
            'establishment_count' => 4,
            'avg_rate' => 0.0,
            'etat_effectifs' => \App\Support\EtatMesure::NON_MESURE,
            'etat_personnel' => \App\Support\EtatMesure::NON_MESURE,
            'etat_finances' => \App\Support\EtatMesure::NON_MESURE,
            'mention_effectifs' => null,
            'mention_personnel' => null,
            'mention_finances' => null,
        ],
    ])->render();

    expect($html)->toContain(\App\Support\EtatMesure::TIRET);
    expect($html)->toContain('aucun établissement mesuré');
    expect($html)->toContain('data-tone="inconnu"');

    // Ni « critique » ni le rouge : un taux qu'on n'a pas n'est pas mauvais.
    expect($html)->not->toContain('data-tone="danger"');
    expect($html)->not->toContain('0,0');

    // Le nombre d'établissements, lui, vient de klassci_master et reste su.
    expect($html)->toContain('4 établissements');
});

it('le hero établissements affiche le périmètre d\'un total amputé', function () {
    $html = view('filament.group.partials.establishments-hero', [
        'context' => [
            'total_students' => 2140,
            'total_staff' => 58,
            'establishment_count' => 4,
            'avg_rate' => 82.0,
            'etat_effectifs' => \App\Support\EtatMesure::MESURE,
            'etat_personnel' => \App\Support\EtatMesure::MESURE,
            'etat_finances' => \App\Support\EtatMesure::MESURE,
            'mention_effectifs' => 'sur 2 des 4 établissements',
            'mention_personnel' => 'sur 2 des 4 établissements',
            'mention_finances' => 'sur 2 des 4 établissements',
        ],
    ])->render();

    // Un total partiel qui ne dit pas qu'il est partiel se lit comme complet.
    expect($html)->toContain('sur 2 des 4 établissements');
    expect($html)->toContain('2 140');
});
