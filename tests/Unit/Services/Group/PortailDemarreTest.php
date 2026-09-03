<?php

use App\Filament\Group\Resources\ReportScheduleResource;
use Filament\Facades\Filament;

/**
 * Une ressource Filament mal câblée ne casse rien à l'écriture : elle fait
 * tomber le panneau entier au premier chargement, en production. Ce test
 * résout chaque ressource et chaque page du portail pour que la panne se
 * voie ici plutôt que devant le directeur.
 */
it('résout toutes les ressources et toutes leurs pages', function () {
    $pagesVerifiees = 0;

    foreach (Filament::getPanel('group')->getResources() as $resource) {
        foreach ($resource::getPages() as $cle => $page) {
            expect(class_exists($page->getPage()))
                ->toBeTrue("La page « {$cle} » de {$resource} est introuvable.");
            $pagesVerifiees++;
        }
    }

    // Sans cette borne, un portail vidé de ses ressources ferait passer le
    // test en ne bouclant sur rien.
    expect($pagesVerifiees)->toBeGreaterThan(0);
});

it('enregistre la ressource des rapports programmés', function () {
    expect(Filament::getPanel('group')->getResources())
        ->toContain(ReportScheduleResource::class);
});

it('garde l écran des rapports programmés caché tant que le drapeau est éteint', function () {
    config()->set('group_portal.scheduled_reports_enabled', false);

    expect(ReportScheduleResource::shouldRegisterNavigation())->toBeFalse()
        ->and(ReportScheduleResource::canAccess())->toBeFalse();

    config()->set('group_portal.scheduled_reports_enabled', true);

    expect(ReportScheduleResource::shouldRegisterNavigation())->toBeTrue()
        ->and(ReportScheduleResource::canAccess())->toBeTrue();
});
