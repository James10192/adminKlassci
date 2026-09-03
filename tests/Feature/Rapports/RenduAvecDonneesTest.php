<?php

use App\Models\Group;
use App\Services\Export\ReportRenderer;
use App\Services\Group\ReportRegistry;
use App\Services\Group\TenantBillingContext;
use App\Services\TenantConnectionManager;
use App\Support\Filtres\FiltresRapport;
use App\Support\Period\PeriodFactory;
use Tests\Feature\Rapports\BaseEcoleSimulee;

/**
 * Les trois états de détail, remplis, rendus en PDF et en HTML.
 *
 * Les autres tests vérifient les LIGNES. Celui-ci vérifie le DOCUMENT : qu'il
 * sort réellement, et qu'on peut le regarder. Un rapport dont chaque valeur est
 * juste peut rester illisible — colonne trop étroite, total mal placé, en-tête
 * qui casse. Le HTML écrit ici sert à cela : le relire à l'œil.
 */
beforeEach(function (): void {
    app()->instance(TenantConnectionManager::class, new BaseEcoleSimulee());
    app(TenantBillingContext::class)->reset();
});

it('rend les trois états remplis, en PDF et en HTML relisible', function (): void {
    $groupe = Group::create(['name' => 'Groupe ROSTAN', 'code' => 'rendu', 'status' => 'active']);
    BaseEcoleSimulee::ecole($groupe, 'yopougon');
    BaseEcoleSimulee::ecole($groupe, 'bouake');
    BaseEcoleSimulee::ecole($groupe, 'muette', repond: false);

    $filtres = new FiltresRapport(
        periode: PeriodFactory::make(PeriodFactory::TYPE_CUSTOM_RANGE, ['start' => '2026-09-01', 'end' => '2026-09-30']),
        statutPaiement: 'validé',
        statutInscription: 'active',
    );

    $registre = app(ReportRegistry::class);
    $rendu = app(ReportRenderer::class);
    $viewData = new ReflectionMethod($rendu, 'viewData');
    $viewData->setAccessible(true);

    $dossier = env('RAPPORTS_RENDU_DIR');

    foreach ([
        ReportRegistry::DETAIL_PAIEMENTS,
        ReportRegistry::SITUATION_ETUDIANTS,
        ReportRegistry::EFFECTIFS_SCOLARITE,
    ] as $cle) {
        $rapport = $registre->construire($cle, $groupe, $filtres);

        expect($rapport->rowCount())->toBeGreaterThan(0, "« {$cle} » ne porte aucune ligne alors que deux écoles ont répondu.");

        $octets = $rendu->pdfBytes($rapport);
        expect(substr($octets, 0, 4))->toBe('%PDF');

        // Le bandeau doit déclarer l'école muette, quel que soit l'état.
        expect($rapport->filters()['Périmètre'])
            ->toContain('2 établissements sur 3')
            ->toContain('MUETTE');

        if ($dossier) {
            file_put_contents("{$dossier}/{$cle}_rempli.html", view($rapport->pdfView(), $viewData->invoke($rendu, $rapport))->render());
        }
    }
});
