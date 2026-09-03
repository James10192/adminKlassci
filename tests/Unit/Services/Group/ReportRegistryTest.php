<?php

use App\Services\Group\ReportRegistry;

beforeEach(function () {
    $this->registry = new ReportRegistry();
});

it('expose les trois états du portail', function () {
    expect($this->registry->options())->toHaveKeys([
        ReportRegistry::ETAT_ETABLISSEMENTS,
        ReportRegistry::CONSOLIDATION_FINANCIERE,
        ReportRegistry::MASSE_SALARIALE,
    ]);
});

it('reconnaît une clé connue et rejette une clé inconnue', function () {
    expect($this->registry->connait(ReportRegistry::MASSE_SALARIALE))->toBeTrue()
        ->and($this->registry->connait('rapport-supprime'))->toBeFalse();
});

it('refuse bruyamment de construire un rapport retiré', function () {
    // Une programmation peut viser un rapport qu'on a supprimé depuis. La
    // commande doit pouvoir le signaler et laisser la trace, pas envoyer un
    // document vide comme si de rien n'était.
    expect(fn () => $this->registry->construire('rapport-supprime', new App\Models\Group()))
        ->toThrow(InvalidArgumentException::class);
});

it('retombe sur la clé quand aucun libellé n existe', function () {
    expect($this->registry->libelle('inconnu'))->toBe('inconnu');
});
