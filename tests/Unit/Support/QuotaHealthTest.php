<?php

use App\Support\QuotaHealth;

// Les seuils vivent dans la configuration : ce test a besoin de l'application
// bootee. Les autres tests de Unit/Support restent volontairement app-less.
uses(Tests\TestCase::class);

/**
 * Le tableau des établissements peignait en VERT une école à 690/700 pendant
 * que le bandeau d'alertes du même portail annonçait « Quota students à
 * 98.6 % ». Les deux lisent maintenant cette classe.
 */
it('déclare critique un quota que le moteur d\'alertes signale déjà', function () {
    expect(QuotaHealth::tone(98.6))->toBe('warning');
});

it('ne déclare dépassé qu\'au-delà du plafond souscrit', function () {
    expect(QuotaHealth::tone(99.9))->toBe('warning');
    expect(QuotaHealth::tone(100.0))->toBe('danger');
    expect(QuotaHealth::tone(140.0))->toBe('danger');
});

it('laisse vert un quota confortable', function () {
    expect(QuotaHealth::tone(0.0))->toBe('success');
    expect(QuotaHealth::tone(77.5))->toBe('success');
    expect(QuotaHealth::tone(89.9))->toBe('success');
});

it('lit ses seuils dans la configuration, pas dans le code', function () {
    config(['group_portal.quota_health.critical' => 75]);

    expect(QuotaHealth::criticalThreshold())->toBe(75.0);
    expect(QuotaHealth::tone(80.0))->toBe('warning');
});

it('traite un plafond absent comme illimité, jamais comme plein', function () {
    // Une division par un plafond nul lèverait ; un 100 % inventé bloquerait
    // une école qui n'a pas de plafond du tout.
    expect(QuotaHealth::percentage(500, 0))->toBe(0.0);
    expect(QuotaHealth::percentage(500, null))->toBe(0.0);
    expect(QuotaHealth::tone(QuotaHealth::percentage(500, null)))->toBe('success');
});

it('calcule le pourcentage réel des cas de production', function () {
    expect(QuotaHealth::percentage(690, 700))->toBe(98.6);
    expect(QuotaHealth::percentage(620, 800))->toBe(77.5);
    expect(QuotaHealth::percentage(2140, 3000))->toBe(71.3);
    expect(QuotaHealth::percentage(null, 50))->toBe(0.0);
});

it('donne à l\'assiduité ses propres seuils, distincts du recouvrement', function () {
    // Un refactor de cette session avait fait lire à l'assiduité le barème du
    // RECOUVREMENT : 72 % passait de « à surveiller » à « sain » sans que
    // personne ne l'ait décidé. Un taux d'encaissement de 72 % est confortable ;
    // 72 % d'assiduité, c'est plus d'un quart des cours manqués.
    expect(\App\Support\RateHealth::tone(72.0))->toBe('success');
    expect(\App\Support\RateHealth::tone(72.0, \App\Support\RateHealth::BAREME_ASSIDUITE))
        ->toBe('warning');

    expect(\App\Support\RateHealth::label(88.0, \App\Support\RateHealth::BAREME_ASSIDUITE))
        ->toBe('sain');
});

it('laisse chaque barème configurable séparément', function () {
    config(['group_portal.attendance_health.healthy' => 95]);

    expect(\App\Support\RateHealth::healthyThreshold(\App\Support\RateHealth::BAREME_ASSIDUITE))
        ->toBe(95.0);
    // Le barème du recouvrement n'a pas bougé.
    expect(\App\Support\RateHealth::healthyThreshold())->toBe(70.0);
});
