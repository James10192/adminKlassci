<?php

use App\Enums\QuotaType;

/**
 * Un nom de colonne n'est pas un mot destiné à être lu.
 *
 * Les cinq clés de quota sont les racines des colonnes `max_*` / `current_*` de
 * la table `tenants`. Elles arrivaient telles quelles dans le texte des
 * alertes : le directeur d'un groupe ivoirien lisait « Quota users à 93,3 % »
 * et « Quota students dépassé » sur un portail par ailleurs entièrement
 * français.
 */
it('couvre les cinq quotas de la table tenants', function () {
    expect(array_map(fn (QuotaType $q) => $q->value, QuotaType::cases()))
        ->toBe(['users', 'staff', 'students', 'inscriptions', 'storage']);
});

it('traduit chaque quota en français', function () {
    // `inscriptions` s'ecrit pareil dans les deux langues : la regle n'est pas
    // « different de la cle », c'est « lisible par un directeur ».
    foreach (QuotaType::cases() as $quota) {
        expect($quota->libelle())->not->toContain('_');
        expect($quota->libelle())->not->toBe('');
    }

    // Les trois qui trahissaient l'anglais dans l'interface.
    expect(QuotaType::Users->libelle())->toBe('comptes');
    expect(QuotaType::Students->libelle())->toBe('étudiants');
    expect(QuotaType::Staff->libelle())->toBe('personnel');
});

it('se dégrade sur une clé inconnue plutôt que de lever', function () {
    // Mieux vaut un mot anglais dans une alerte qu'une alerte qui ne s'affiche
    // pas : ces clés voyagent en tableau, dans des charges de cache écrites par
    // une version anterieure du code.
    expect(QuotaType::libelleDe('cpu'))->toBe('cpu');
    expect(QuotaType::libelleDe(null))->toBe('inconnu');
    expect(QuotaType::libelleDe(''))->toBe('inconnu');
});
