<?php

use App\Services\Parc\EtatParcResolver;
use Carbon\CarbonImmutable;

/**
 * Le défaut que ces tests verrouillent : le tableau de bord comptait les
 * établissements jamais vérifiés parmi les opérationnels. Un parc jamais sondé
 * s'affichait donc entièrement en vert.
 */
beforeEach(function () {
    $this->r = new EtatParcResolver;
    $this->maintenant = CarbonImmutable::parse('2026-09-03 10:00:00');
});

it('ne compte JAMAIS un etablissement sans releve parmi les operationnels', function () {
    $repartition = $this->r->repartir([
        1 => ['statut' => null, 'releve_a' => null],
        2 => ['statut' => null, 'releve_a' => null],
    ], $this->maintenant, 15);

    expect($repartition[EtatParcResolver::OPERATIONNEL])->toBe(0)
        ->and($repartition[EtatParcResolver::SANS_RELEVE])->toBe(2)
        ->and($repartition['total'])->toBe(2);
});

it('range chaque statut releve dans sa case', function () {
    $frais = $this->maintenant->subMinutes(2);

    $repartition = $this->r->repartir([
        1 => ['statut' => 'healthy', 'releve_a' => $frais],
        2 => ['statut' => 'degraded', 'releve_a' => $frais],
        3 => ['statut' => 'unhealthy', 'releve_a' => $frais],
        4 => ['statut' => null, 'releve_a' => null],
    ], $this->maintenant, 15);

    expect($repartition[EtatParcResolver::OPERATIONNEL])->toBe(1)
        ->and($repartition[EtatParcResolver::DEGRADE])->toBe(1)
        ->and($repartition[EtatParcResolver::CRITIQUE])->toBe(1)
        ->and($repartition[EtatParcResolver::SANS_RELEVE])->toBe(1);
});

it('ne reconduit pas indefiniment un verdict perime', function () {
    // Sain, mais relevé il y a une heure : on ne sait plus.
    $etat = $this->r->etat('healthy', $this->maintenant->subHour(), $this->maintenant->subMinutes(15));

    expect($etat)->toBe(EtatParcResolver::SANS_RELEVE);
});

it('garde un releve tout juste dans la fenetre', function () {
    $horizon = $this->maintenant->subMinutes(15);
    $etat = $this->r->etat('healthy', $this->maintenant->subMinutes(14), $horizon);

    expect($etat)->toBe(EtatParcResolver::OPERATIONNEL);
});

it('un statut inconnu ne passe pas pour operationnel', function () {
    $etat = $this->r->etat('quelque_chose', $this->maintenant, $this->maintenant->subMinutes(15));

    expect($etat)->toBe(EtatParcResolver::SANS_RELEVE);
});

it('un parc vide ne totalise rien', function () {
    $repartition = $this->r->repartir([], $this->maintenant, 15);

    expect($repartition['total'])->toBe(0)
        ->and($repartition[EtatParcResolver::OPERATIONNEL])->toBe(0);
});

it('une fraicheur nulle ou negative ne fait pas tout basculer', function () {
    // Un opérateur qui pose 0 dans .env ne doit pas voir tout son parc
    // basculer en « sans relevé » : la borne est ramenée à une minute.
    $repartition = $this->r->repartir([
        1 => ['statut' => 'healthy', 'releve_a' => $this->maintenant->subSeconds(10)],
    ], $this->maintenant, 0);

    expect($repartition[EtatParcResolver::OPERATIONNEL])->toBe(1);
});

it('accepte une date sous forme de chaine, comme la renvoie la base', function () {
    $etat = $this->r->etat('unhealthy', '2026-09-03 09:58:00', $this->maintenant->subMinutes(15));

    expect($etat)->toBe(EtatParcResolver::CRITIQUE);
});
