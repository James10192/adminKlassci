<?php

use App\Enums\AlertType;

/**
 * La tuile d'alertes du tableau de bord affichait `{{ $alert['type'] }}` brut,
 * que le CSS passait en capitales : un directeur général lisait
 * « QUOTA_CRITICAL » et « SUBSCRIPTION_EXPIRING » sur son propre portail.
 */
it('donne un libellé français à chaque type, sans identifiant technique', function () {
    foreach (AlertType::cases() as $type) {
        $libelle = $type->libelle();

        expect($libelle)->not->toBe('');
        expect($libelle)->not->toContain('_');
        expect($libelle)->not->toBe(strtoupper($libelle));
    }
});

it('n\'emploie pas le mot « tenant », qui est celui du code', function () {
    // « Tenant inactif » parlait au développeur, pas au directeur d'école.
    foreach (AlertType::cases() as $type) {
        expect(mb_strtolower($type->libelle()))->not->toContain('tenant');
    }
});

it('traduit une valeur reçue en chaîne, comme les alertes en cache', function () {
    expect(AlertType::libelleDe('quota_critical'))->toBe('Quota critique');
    expect(AlertType::libelleDe('subscription_expiring'))->toBe('Abonnement expirant');
});

it('dégrade un type inconnu en texte lisible plutôt qu\'en 500', function () {
    // Une alerte mise en cache avant un déploiement qui retire son type ne
    // doit pas casser le tableau de bord du fondateur.
    expect(AlertType::libelleDe('type_disparu'))->toBe('Type disparu');
    expect(AlertType::libelleDe(null))->toBe('Alerte');
    expect(AlertType::libelleDe(''))->toBe('Alerte');
});

it('expose la table complète pour peupler un filtre', function () {
    $libelles = AlertType::libelles();

    expect($libelles)->toHaveCount(count(AlertType::cases()));
    expect($libelles['quota_exceeded'])->toBe('Quota dépassé');
});
