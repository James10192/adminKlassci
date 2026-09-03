<?php

use App\Models\Group;
use App\Services\Group\Detail\FournisseurEffectifs;
use App\Services\Group\Detail\FournisseurSituationEtudiants;
use App\Services\Group\ReportRegistry;
use App\Services\Group\TenantBillingContext;
use App\Services\TenantConnectionManager;
use App\Support\Filtres\FiltresRapport;
use App\Support\Period\PeriodFactory;
use Tests\Feature\Rapports\BaseEcoleSimulee;

/**
 * La situation par étudiant et les effectifs, sur de vraies bases d'école.
 *
 * Le jeu de données est calibré : barème unique de 500 000 par étudiant, deux
 * inscrits, un versement de 200 000 validé, un de 500 000 validé, un de 300 000
 * EN ATTENTE et un de 999 999 SUPPRIMÉ. Les deux derniers sont là pour être
 * exclus — s'ils entraient dans l'encaissé, les dossiers à relancer
 * disparaîtraient précisément de la liste de relance.
 */
beforeEach(function (): void {
    app()->instance(TenantConnectionManager::class, new BaseEcoleSimulee());
    app(TenantBillingContext::class)->reset();
});

it('ne compte comme encaissé que les paiements validés', function (): void {
    $groupe = Group::create(['name' => 'Groupe', 'code' => 's1', 'status' => 'active']);
    BaseEcoleSimulee::ecole($groupe, 'sitalpha');

    $lignes = app(FournisseurSituationEtudiants::class)
        ->pourGroupe($groupe, new FiltresRapport(statutInscription: 'active'))['lignes'];

    expect($lignes)->toHaveCount(2);

    $parMatricule = collect($lignes)->keyBy('matricule');

    // 500 000 attendus, 200 000 validés. Les 300 000 en attente ne comptent pas :
    // le reste est donc 300 000, pas zéro.
    expect($parMatricule['M001']['attendu'])->toBe(500000.0)
        ->and($parMatricule['M001']['encaisse'])->toBe(200000.0)
        ->and($parMatricule['M001']['reste'])->toBe(300000.0);

    // 500 000 attendus, 500 000 validés. Le versement supprimé de 999 999
    // n'est pas venu gonfler l'encaissé.
    expect($parMatricule['M002']['encaisse'])->toBe(500000.0)
        ->and($parMatricule['M002']['reste'])->toBe(0.0);
});

it('classe les plus gros restes en tête, pour que la relance se lise', function (): void {
    $groupe = Group::create(['name' => 'Groupe', 'code' => 's2', 'status' => 'active']);
    BaseEcoleSimulee::ecole($groupe, 'sitbeta');

    $lignes = app(FournisseurSituationEtudiants::class)
        ->pourGroupe($groupe, new FiltresRapport(statutInscription: 'active'))['lignes'];

    expect($lignes[0]['matricule'])->toBe('M001')  // reste 300 000
        ->and($lignes[1]['matricule'])->toBe('M002'); // reste 0
});

it('ne rend jamais un reste négatif', function (): void {
    $groupe = Group::create(['name' => 'Groupe', 'code' => 's3', 'status' => 'active']);
    BaseEcoleSimulee::ecole($groupe, 'sitgamma');

    $lignes = app(FournisseurSituationEtudiants::class)
        ->pourGroupe($groupe, new FiltresRapport(statutInscription: 'active'))['lignes'];

    foreach ($lignes as $ligne) {
        expect($ligne['reste'])->toBeGreaterThanOrEqual(0.0);
    }
});

it('totalise l\'attendu, l\'encaissé et le reste du groupe', function (): void {
    $groupe = Group::create(['name' => 'Groupe', 'code' => 's4', 'status' => 'active']);
    BaseEcoleSimulee::ecole($groupe, 'sitdelta');

    $totaux = app(ReportRegistry::class)
        ->construire(ReportRegistry::SITUATION_ETUDIANTS, $groupe, new FiltresRapport(statutInscription: 'active'))
        ->totaux();

    expect($totaux[4])->toBe(1000000.0)   // attendu
        ->and($totaux[5])->toBe(700000.0) // encaissé
        ->and($totaux[6])->toBe(300000.0); // reste
});

it('liste les effectifs avec leur classe, filière et niveau', function (): void {
    $groupe = Group::create(['name' => 'Groupe', 'code' => 'e1', 'status' => 'active']);
    BaseEcoleSimulee::ecole($groupe, 'effalpha');

    $lignes = app(FournisseurEffectifs::class)
        ->pourGroupe($groupe, new FiltresRapport(statutInscription: 'active'))['lignes'];

    expect($lignes)->toHaveCount(2)
        ->and($lignes[0]['classe'])->toBe('L1 GC A')
        ->and($lignes[0]['filiere'])->toBe('Génie civil')
        ->and($lignes[0]['niveau'])->toBe('Licence 1');
});

it('ne fait sortir aucune donnée de contact', function (): void {
    $groupe = Group::create(['name' => 'Groupe', 'code' => 'e2', 'status' => 'active']);
    BaseEcoleSimulee::ecole($groupe, 'effbeta');

    $ligne = app(FournisseurEffectifs::class)
        ->pourGroupe($groupe, new FiltresRapport(statutInscription: 'active'))['lignes'][0];

    // Le choix du fondateur est « scolarité seule ». Ce test est le garde-fou
    // qui le rend visible : ajouter un téléphone au rapport le fera rougir, et
    // celui qui l'ajoutera devra donc le DÉCIDER, pas simplement le coder.
    foreach (['telephone', 'email', 'email_personnel', 'date_naissance', 'adresse'] as $interdit) {
        expect($ligne)->not->toHaveKey($interdit);
    }
});

it('compte la répartition par sexe sous les effectifs', function (): void {
    $groupe = Group::create(['name' => 'Groupe', 'code' => 'e3', 'status' => 'active']);
    BaseEcoleSimulee::ecole($groupe, 'effgamma');

    $totaux = app(ReportRegistry::class)
        ->construire(ReportRegistry::EFFECTIFS_SCOLARITE, $groupe, new FiltresRapport(statutInscription: 'active'))
        ->totaux();

    expect($totaux[0])->toContain('2 inscrits')
        ->and($totaux[4])->toBe('1 H · 1 F');
});

it('n\'annonce aucun filtre que le document n\'applique pas', function (): void {
    $groupe = Group::create(['name' => 'Groupe', 'code' => 'ban1', 'status' => 'active']);
    BaseEcoleSimulee::ecole($groupe, 'bandeau');

    // Tous les filtres renseignés d'un coup : c'est la situation qui révélait
    // le défaut. « Effectifs et scolarité » annonçait « Statut du paiement :
    // Validé », qui n'a rien à voir avec une liste d'inscrits, et « Période :
    // septembre » alors qu'il couvre toute l'année universitaire. Un lecteur ne
    // vérifie pas un cadrage qu'on lui a annoncé.
    $tout = new FiltresRapport(
        periode: PeriodFactory::make(PeriodFactory::TYPE_CUSTOM_RANGE, ['start' => '2026-09-01', 'end' => '2026-09-30']),
        statutPaiement: 'validé',
        modePaiement: 'Espèces',
        statutInscription: 'active',
    );

    $registre = app(ReportRegistry::class);

    $attendus = [
        ReportRegistry::DETAIL_PAIEMENTS => ['Période', 'Statut du paiement', 'Mode'],
        ReportRegistry::SITUATION_ETUDIANTS => ['Inscription'],
        ReportRegistry::EFFECTIFS_SCOLARITE => ['Inscription'],
    ];

    foreach ($attendus as $cle => $honores) {
        // « Portée » et « Périmètre » sont posés par le document lui-même, pas
        // par le cadrage : ils ne sont pas des filtres.
        $montres = array_values(array_diff(
            array_keys($registre->construire($cle, $groupe, $tout)->filters()),
            ['Portée', 'Périmètre', 'Établissements'],
        ));

        expect($montres)->toBe($honores, "Le bandeau de « {$cle} » annonce un cadrage qu'il n'applique pas.");
    }
});
