<?php

use App\Models\Group;
use App\Services\Group\Detail\FournisseurDetailPaiements;
use App\Services\Group\ReportRegistry;
use App\Services\TenantConnectionManager;
use App\Support\Filtres\FiltresRapport;
use App\Support\Period\PeriodFactory;
use Tests\Feature\Rapports\BaseEcoleSimulee;

/**
 * Le journal des encaissements, sur de vraies bases d'école.
 *
 * Ces tests montent le schéma réel en SQLite et le remplissent, plutôt que
 * d'injecter des lignes déjà calculées : c'est la seule façon de vérifier les
 * jointures, les noms de colonnes et les filtres — qui sont précisément la
 * fonctionnalité, ici.
 */
function periodeCouvrante(): FiltresRapport
{
    return new FiltresRapport(
        periode: PeriodFactory::make(PeriodFactory::TYPE_CUSTOM_RANGE, [
            'start' => '2026-09-01',
            'end' => '2026-09-30',
        ]),
        statutPaiement: 'validé',
    );
}

beforeEach(function (): void {
    app()->instance(TenantConnectionManager::class, new BaseEcoleSimulee());
    app(\App\Services\Group\TenantBillingContext::class)->reset();
});

it('remonte les paiements validés, et eux seuls', function (): void {
    $groupe = Group::create(['name' => 'Groupe', 'code' => 'g1', 'status' => 'active']);
    BaseEcoleSimulee::ecole($groupe, 'alpha');

    $resultat = app(FournisseurDetailPaiements::class)->pourGroupe($groupe, periodeCouvrante());

    expect($resultat['lignes'])->toHaveCount(2)
        ->and($resultat['repondants'])->toBe(1)
        ->and($resultat['manquants'])->toBe([]);

    $montants = array_column($resultat['lignes'], 'montant');
    expect($montants)->toBe([200000.0, 500000.0]);
});

it('exclut le paiement supprimé, qui n\'est pas une recette', function (): void {
    $groupe = Group::create(['name' => 'Groupe', 'code' => 'g2', 'status' => 'active']);
    BaseEcoleSimulee::ecole($groupe, 'beta');

    $resultat = app(FournisseurDetailPaiements::class)->pourGroupe($groupe, periodeCouvrante());

    $references = array_column($resultat['lignes'], 'reference');
    expect($references)->not->toContain('ANNULE');
});

it('retombe sur le numéro de reçu quand la référence manque', function (): void {
    $groupe = Group::create(['name' => 'Groupe', 'code' => 'g3', 'status' => 'active']);
    BaseEcoleSimulee::ecole($groupe, 'gamma');

    $resultat = app(FournisseurDetailPaiements::class)->pourGroupe($groupe, periodeCouvrante());

    expect(array_column($resultat['lignes'], 'reference'))->toContain('RECU-9');
});

it('compose le nom complet et rattache la classe', function (): void {
    $groupe = Group::create(['name' => 'Groupe', 'code' => 'g4', 'status' => 'active']);
    BaseEcoleSimulee::ecole($groupe, 'delta');

    $ligne = app(FournisseurDetailPaiements::class)->pourGroupe($groupe, periodeCouvrante())['lignes'][0];

    expect($ligne['etudiant'])->toBe('KOUAME Awa')
        ->and($ligne['classe'])->toBe('L1 GC A')
        ->and($ligne['matricule'])->toBe('M001')
        ->and($ligne['etablissement'])->toBe('DELTA');
});

it('déclare l\'école qui n\'a pas répondu, au lieu de la taire', function (): void {
    $groupe = Group::create(['name' => 'Groupe', 'code' => 'g5', 'status' => 'active']);
    BaseEcoleSimulee::ecole($groupe, 'presente');
    BaseEcoleSimulee::ecole($groupe, 'absente', repond: false);

    $resultat = app(FournisseurDetailPaiements::class)->pourGroupe($groupe, periodeCouvrante());

    expect($resultat['total'])->toBe(2)
        ->and($resultat['repondants'])->toBe(1)
        ->and($resultat['manquants'])->toHaveKey('absente');

    // Et surtout : le document le DIT. Une école muette ne laisse aucune ligne,
    // donc aucune trace visible — sauf ici.
    $rapport = app(ReportRegistry::class)->construire(
        ReportRegistry::DETAIL_PAIEMENTS,
        $groupe,
        periodeCouvrante(),
    );

    expect($rapport->filters()['Périmètre'])
        ->toContain('1 établissement sur 2')
        ->toContain('ABSENTE');
});

it('ne totalise pas quand les statuts sont mêlés', function (): void {
    $groupe = Group::create(['name' => 'Groupe', 'code' => 'g6', 'status' => 'active']);
    BaseEcoleSimulee::ecole($groupe, 'epsilon');

    $melanges = new FiltresRapport(
        periode: PeriodFactory::make(PeriodFactory::TYPE_CUSTOM_RANGE, ['start' => '2026-09-01', 'end' => '2026-09-30']),
    );

    $rapport = app(ReportRegistry::class)->construire(ReportRegistry::DETAIL_PAIEMENTS, $groupe, $melanges);

    expect($rapport->totaux()[1])->toContain('non totalisé');
});

it('totalise quand le statut est unique', function (): void {
    $groupe = Group::create(['name' => 'Groupe', 'code' => 'g7', 'status' => 'active']);
    BaseEcoleSimulee::ecole($groupe, 'zeta');

    $rapport = app(ReportRegistry::class)->construire(
        ReportRegistry::DETAIL_PAIEMENTS,
        $groupe,
        periodeCouvrante(),
    );

    $totaux = $rapport->totaux();

    expect($totaux[0])->toBe('TOTAL')
        ->and($totaux[1])->toContain('Validé')
        ->and($totaux[1])->toContain('2 paiements')
        ->and($totaux[7])->toBe(700000.0);
});
