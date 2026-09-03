<?php

use App\Services\Group\ScheduleDueResolver;
use Carbon\CarbonImmutable;

beforeEach(function () {
    $this->resolver = new ScheduleDueResolver();
});

/** Mercredi 3 septembre 2026, 9 h. */
function maintenantDemo(string $iso = '2026-09-02 09:00:00'): CarbonImmutable
{
    return CarbonImmutable::parse($iso);
}

it('n envoie pas avant le moment prévu', function () {
    // Prévu mercredi 7 h ; on est mercredi 6 h.
    $due = $this->resolver->estDue(
        ScheduleDueResolver::HEBDOMADAIRE,
        jourSemaine: 3, jourMois: null, heure: 7,
        dernierEnvoi: null,
        maintenant: maintenantDemo('2026-09-02 06:00:00'),
    );

    expect($due)->toBeFalse();
});

it('envoie une fois le moment passé', function () {
    $due = $this->resolver->estDue(
        ScheduleDueResolver::HEBDOMADAIRE,
        jourSemaine: 3, jourMois: null, heure: 7,
        dernierEnvoi: null,
        maintenant: maintenantDemo('2026-09-02 09:00:00'),
    );

    expect($due)->toBeTrue();
});

it('n envoie pas deux fois dans la même semaine', function () {
    // La commande tourne toutes les heures : sans cette garde, le rapport
    // repartirait à 8 h, 9 h, 10 h…
    $due = $this->resolver->estDue(
        ScheduleDueResolver::HEBDOMADAIRE,
        jourSemaine: 3, jourMois: null, heure: 7,
        dernierEnvoi: CarbonImmutable::parse('2026-09-02 07:05:00'),
        maintenant: maintenantDemo('2026-09-02 09:00:00'),
    );

    expect($due)->toBeFalse();
});

it('rattrape un envoi manqué plus tard dans la journée', function () {
    // Serveur occupé à 7 h. À 23 h le rapport doit encore partir : un rapport
    // qui n'arrive pas ne fait aucun bruit, personne ne le réclamerait.
    $due = $this->resolver->estDue(
        ScheduleDueResolver::HEBDOMADAIRE,
        jourSemaine: 3, jourMois: null, heure: 7,
        dernierEnvoi: CarbonImmutable::parse('2026-08-26 07:05:00'), // semaine precedente
        maintenant: maintenantDemo('2026-09-02 23:00:00'),
    );

    expect($due)->toBeTrue();
});

it('rattrape aussi un envoi manqué plus tard dans la semaine', function () {
    // Panne mercredi entier. Vendredi, le rapport de la semaine part quand même.
    $due = $this->resolver->estDue(
        ScheduleDueResolver::HEBDOMADAIRE,
        jourSemaine: 3, jourMois: null, heure: 7,
        dernierEnvoi: CarbonImmutable::parse('2026-08-26 07:05:00'),
        maintenant: maintenantDemo('2026-09-04 10:00:00'),
    );

    expect($due)->toBeTrue();
});

it('repart la semaine suivante', function () {
    $due = $this->resolver->estDue(
        ScheduleDueResolver::HEBDOMADAIRE,
        jourSemaine: 3, jourMois: null, heure: 7,
        dernierEnvoi: CarbonImmutable::parse('2026-09-02 07:05:00'),
        maintenant: maintenantDemo('2026-09-09 07:30:00'),
    );

    expect($due)->toBeTrue();
});

it('ramène le 31 au dernier jour des mois courts', function () {
    // Sans ce repli, une programmation « le 31 » sauterait février, avril,
    // juin, septembre et novembre — sans rien signaler.
    $moment = $this->resolver->momentPrevu(
        ScheduleDueResolver::MENSUEL,
        jourSemaine: null, jourMois: 31, heure: 7,
        maintenant: CarbonImmutable::parse('2027-02-15 09:00:00'),
    );

    expect($moment->format('Y-m-d H:i'))->toBe('2027-02-28 07:00');
});

it('envoie le mensuel une seule fois par mois', function () {
    $args = [ScheduleDueResolver::MENSUEL, null, 5, 7];

    $premierPassage = $this->resolver->estDue(...$args, dernierEnvoi: null, maintenant: maintenantDemo('2026-09-05 08:00:00'));
    $secondPassage = $this->resolver->estDue(...$args, dernierEnvoi: CarbonImmutable::parse('2026-09-05 08:00:00'), maintenant: maintenantDemo('2026-09-20 08:00:00'));
    $moisSuivant = $this->resolver->estDue(...$args, dernierEnvoi: CarbonImmutable::parse('2026-09-05 08:00:00'), maintenant: maintenantDemo('2026-10-05 08:00:00'));

    expect($premierPassage)->toBeTrue()
        ->and($secondPassage)->toBeFalse()
        ->and($moisSuivant)->toBeTrue();
});

it('borne une heure ou un jour aberrants au lieu de planter', function () {
    // Une valeur hors bornes en base ne doit pas faire tomber la commande
    // pour toutes les autres programmations du groupe.
    $moment = $this->resolver->momentPrevu(
        ScheduleDueResolver::HEBDOMADAIRE,
        jourSemaine: 99, jourMois: null, heure: 42,
        maintenant: maintenantDemo(),
    );

    expect($moment->format('H:i'))->toBe('23:00')
        ->and($moment->dayOfWeekIso)->toBe(7);
});
