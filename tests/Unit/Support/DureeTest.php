<?php

use App\Support\Duree;

it('accorde le pluriel des jours', function () {
    // « expire dans 1 jours » : sur l'alerte la plus urgente qu'un fondateur
    // verra, un pluriel fautif fait douter du reste du tableau.
    expect(Duree::jours(0))->toBe('0 jour')
        ->and(Duree::jours(1))->toBe('1 jour')
        ->and(Duree::jours(2))->toBe('2 jours')
        ->and(Duree::jours(547))->toBe('547 jours');
});

it('accorde aussi un compte négatif, que l appelant présente comme il veut', function () {
    // `abs()` est du ressort de la phrase (« expiré depuis »), pas du compteur.
    expect(Duree::jours(-1))->toBe('-1 jour')
        ->and(Duree::jours(-12))->toBe('-12 jours');
});
