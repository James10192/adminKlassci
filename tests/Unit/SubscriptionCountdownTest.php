<?php

use App\Support\SubscriptionCountdown;
use Carbon\Carbon;

/**
 * Le tableau de bord affichait « Dans 26.948242395116j » dans la colonne
 * Expiration : Carbon 3 renvoie un flottant depuis diffInDays(), et trois
 * écrans faisaient chacun leur propre arithmétique sur cette valeur.
 *
 * Ces tests pincent le rendu — un jour reste un entier, et les échéances
 * proches se lisent en français plutôt qu'en décompte brut.
 */
it('ne rend jamais de decimale dans un decompte', function () {
    foreach ([0, 1, 2, 7, 29, 30] as $days) {
        expect(SubscriptionCountdown::label($days))->not->toContain('.');
    }
});

it('nomme aujourd hui et demain plutot que 0 j et 1 j', function () {
    expect(SubscriptionCountdown::label(0))->toBe("Aujourd'hui")
        ->and(SubscriptionCountdown::label(1))->toBe('Demain');
});

it('decompte les echeances proches et date les lointaines', function () {
    $lointaine = Carbon::parse('2027-03-18');

    expect(SubscriptionCountdown::label(30, $lointaine))->toBe('Dans 30 j')
        ->and(SubscriptionCountdown::label(31, $lointaine))->toBe('18/03/2027');
});

it('retombe sur un decompte quand aucune date n est fournie', function () {
    expect(SubscriptionCountdown::label(120))->toBe('Dans 120 j');
});

it('dit expire pour une echeance passee', function () {
    expect(SubscriptionCountdown::label(-1))->toBe('Expiré')
        ->and(SubscriptionCountdown::label(-400))->toBe('Expiré');
});

it('affiche le libelle d absence choisi par l appelant', function () {
    expect(SubscriptionCountdown::label(null))->toBe('N/A')
        ->and(SubscriptionCountdown::label(null, null, '—'))->toBe('—');
});

it('colore selon l urgence', function () {
    expect(SubscriptionCountdown::tone(null))->toBe('gray')
        ->and(SubscriptionCountdown::tone(-1))->toBe('danger')
        ->and(SubscriptionCountdown::tone(0))->toBe('warning')
        ->and(SubscriptionCountdown::tone(30))->toBe('warning')
        ->and(SubscriptionCountdown::tone(31))->toBe('success');
});

it('se tait dans l en-tete tant que l echeance est lointaine', function () {
    expect(SubscriptionCountdown::headerNote(31))->toBeNull()
        ->and(SubscriptionCountdown::headerNote(null))->toBeNull()
        ->and(SubscriptionCountdown::headerNote(30))->toBe('Expire dans 30 j')
        ->and(SubscriptionCountdown::headerNote(0))->toBe("Abonnement expirant aujourd'hui")
        ->and(SubscriptionCountdown::headerNote(-3))->toBe('Abonnement expiré');
});

it('ne laisse plus aucun calcul de jours dans les ecrans Filament', function () {
    // Chemin relatif plutôt que app_path() : ce test tourne dans la suite
    // Unit, qui ne démarre pas l'application Laravel.
    $racine = dirname(__DIR__, 2) . '/app/';

    $ecrans = [
        $racine . 'Filament/Widgets/TenantsTableWidget.php',
        $racine . 'Filament/Resources/TenantResource.php',
        $racine . 'Filament/Resources/TenantResource/Pages/ViewTenant.php',
    ];

    foreach ($ecrans as $fichier) {
        $source = file_get_contents($fichier);

        expect($source)
            ->toContain('SubscriptionCountdown::')
            ->and($source)->not->toContain('diffInDays');
    }
});
