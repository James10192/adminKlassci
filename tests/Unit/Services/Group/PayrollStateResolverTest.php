<?php

use App\Services\Group\PayrollStateResolver;

/**
 * La table de paie porte deux colonnes de statut d'époques différentes, et
 * c'est là que se joue la justesse de la masse salariale. Ces cas couvrent
 * la cohabitation, pas la syntaxe.
 */
beforeEach(function () {
    $this->resolver = new PayrollStateResolver();
});

it('lit le workflow quand il a bougé', function (string $workflow, string $attendu) {
    expect($this->resolver->resolve($workflow, 'en attente'))->toBe($attendu);
})->with([
    ['paye', PayrollStateResolver::PAYE],
    ['valide', PayrollStateResolver::ENGAGE],
    ['brouillon', PayrollStateResolver::BROUILLON],
    ['annule', PayrollStateResolver::ANNULE],
]);

it('rattrape les bulletins d avant juin 2026, restés en brouillon alors qu ils ont été versés', function () {
    // La migration du workflow n'a rien rétro-rempli : toute paie antérieure
    // porte « brouillon ». Sans ce repli, elle disparaîtrait de la masse
    // salariale sans qu'aucune erreur ne le signale.
    expect($this->resolver->resolve('brouillon', 'payé'))->toBe(PayrollStateResolver::PAYE);
});

it('laisse en brouillon un bulletin legacy simplement en attente', function () {
    expect($this->resolver->resolve('brouillon', 'en attente'))->toBe(PayrollStateResolver::BROUILLON);
});

it('fait primer l annulation sur un paiement legacy', function () {
    // Bulletin versé sous l'ancien système puis annulé dans le nouveau :
    // il ne doit peser ni sur le coût ni sur la trésorerie.
    expect($this->resolver->resolve('annule', 'payé'))->toBe(PayrollStateResolver::ANNULE);
});

it('reconnaît une annulation portée par la seule ancienne colonne', function () {
    expect($this->resolver->resolve('brouillon', 'annulé'))->toBe(PayrollStateResolver::ANNULE);
});

it('ne se laisse pas piéger par le statut legacy resté à sa valeur par défaut', function () {
    // Depuis juin 2026 `statut` n'est plus mis à jour : il garde « en attente »
    // pendant que le workflow avance. Le workflow doit gagner.
    expect($this->resolver->resolve('paye', 'en attente'))->toBe(PayrollStateResolver::PAYE);
    expect($this->resolver->resolve('valide', 'en attente'))->toBe(PayrollStateResolver::ENGAGE);
});

it('tolère des colonnes absentes ou vides sans rien inventer', function () {
    expect($this->resolver->resolve(null, null))->toBe(PayrollStateResolver::BROUILLON);
    expect($this->resolver->resolve('', ''))->toBe(PayrollStateResolver::BROUILLON);
    expect($this->resolver->resolve('  paye  ', null))->toBe(PayrollStateResolver::PAYE);
});

it('ne fait peser sur le résultat que le versé et l engagé', function () {
    expect($this->resolver->peseSurLeResultat(PayrollStateResolver::PAYE))->toBeTrue()
        ->and($this->resolver->peseSurLeResultat(PayrollStateResolver::ENGAGE))->toBeTrue()
        ->and($this->resolver->peseSurLeResultat(PayrollStateResolver::BROUILLON))->toBeFalse()
        ->and($this->resolver->peseSurLeResultat(PayrollStateResolver::ANNULE))->toBeFalse();
});
