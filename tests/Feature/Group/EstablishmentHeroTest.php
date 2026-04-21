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
