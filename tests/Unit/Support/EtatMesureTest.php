<?php

use App\Support\EtatMesure;

/**
 * Le vocabulaire des trois états. Ces libellés partent à l'écran ET dans les
 * états PDF signés qui circulent sans l'écran qui les a produits — s'ils
 * dérivent, ils dérivent partout à la fois.
 */
it('ne dit jamais « Erreur » d une école qui n a simplement pas répondu', function () {
    // Le badge accusait l'école d'une faute qu'elle n'a pas commise, en rouge,
    // et ne disait rien d'utile au fondateur.
    expect(EtatMesure::badge(EtatMesure::NON_MESURE))->toBe('Non mesuré');
    expect(EtatMesure::badge(EtatMesure::RELEVE))->toBe('Dernier relevé');
    expect(EtatMesure::badge(EtatMesure::MESURE))->toBe('Mesuré');
});

it('nomme le cas où la base a répondu mais l année n est pas ouverte', function () {
    // Ce n'est pas une panne. Les deux tombaient dans le même sac.
    expect(EtatMesure::badge(EtatMesure::NON_MESURE, EtatMesure::MOTIF_SANS_ANNEE))
        ->toBe('Année non configurée');
});

it('ne rend une valeur que pour une mesure ou un relevé', function () {
    expect(EtatMesure::aUneValeur(EtatMesure::MESURE))->toBeTrue();
    expect(EtatMesure::aUneValeur(EtatMesure::RELEVE))->toBeTrue();
    expect(EtatMesure::aUneValeur(EtatMesure::NON_MESURE))->toBeFalse();
    expect(EtatMesure::aUneValeur(EtatMesure::NON_APPLICABLE))->toBeFalse();
});

it('traite un relevé comme une valeur mais pas comme une mesure', function () {
    // La nuance porte tout : un total qui contient un relevé s'affiche, mais
    // il ne peut plus se présenter comme une mesure du présent.
    expect(EtatMesure::estMesure(EtatMesure::RELEVE))->toBeFalse();
    expect(EtatMesure::aUneValeur(EtatMesure::RELEVE))->toBeTrue();
});

it('considère un état absent comme mesuré', function () {
    // Rétrocompatibilité : tous les tableaux produits avant l'ajout des états
    // sont, de fait, des mesures. Sans ce défaut, chaque appelant non encore
    // migré afficherait des tirets.
    expect(EtatMesure::estMesure(null))->toBeTrue();
    expect(EtatMesure::aUneValeur(null))->toBeTrue();
});

it('se tait quand le périmètre est complet', function () {
    // La seule situation où le portail n'ajoute rien sous un chiffre.
    expect(EtatMesure::mentionPerimetre(4, 4))->toBeNull();
});

it('nomme le dénominateur quand le total est amputé', function () {
    expect(EtatMesure::mentionPerimetre(3, 4))->toBe('sur 3 des 4 établissements');
});

it('ne dit pas « sur 1 des 1 » à un groupe d une seule école', function () {
    // La formule serait grotesque, et l'état seul suffit à cette échelle.
    expect(EtatMesure::mentionPerimetre(0, 1))->toBeNull();
});

it('annonce les relevés contenus dans un total', function () {
    expect(EtatMesure::mentionReleves(1))->toBe('dont 1 établissement au dernier relevé');
    expect(EtatMesure::mentionReleves(2))->toBe('dont 2 établissements au dernier relevé');
    expect(EtatMesure::mentionReleves(0))->toBeNull();
});

it('explique pourquoi il n y a pas de chiffre', function () {
    expect(EtatMesure::libelleMotif(EtatMesure::MOTIF_INJOIGNABLE))
        ->toBe("la base de l'établissement n'a pas répondu");
    expect(EtatMesure::libelleMotif(EtatMesure::MOTIF_SANS_ANNEE))
        ->toBe("aucune année universitaire n'est en cours");
    expect(EtatMesure::libelleMotif(EtatMesure::MOTIF_SANS_MODULE))
        ->toBe("l'établissement n'utilise pas ce module");
});

it('n affiche jamais zéro à la place d une valeur absente', function () {
    // Le zéro est libéré : il ne veut plus dire « on ne sait pas ». C'est ce
    // qui permet enfin à une école qui vient d'ouvrir d'afficher « 0 étudiant »
    // et d'être crue.
    expect(EtatMesure::TIRET)->not->toBe('0');
    expect(EtatMesure::TIRET)->toBe('—');
});

it('ne laisse qu\'une seule façon de dire qu\'un total de groupe n\'est pas mesuré', function () {
    // Dans la même rangée du hero, « Étudiants inscrits » disait « non mesuré »
    // pendant que « Recouvrement », juste à côté, disait « aucun établissement
    // mesuré » — pour exactement le même état. La phrase était retapée dans
    // chaque vue, donc elle avait dérivé.
    expect(EtatMesure::absenceGroupe())->toBe('aucun établissement mesuré');
});

it('ne confond pas l\'absence d\'un groupe avec celle d\'un établissement', function () {
    // Un total de groupe ne peut pas nommer une cause : plusieurs écoles,
    // plusieurs raisons possibles. Une carte d'établissement, elle, le doit.
    expect(EtatMesure::absenceGroupe())->not->toBe(EtatMesure::libelleMotif(null));
    expect(EtatMesure::libelleMotif(EtatMesure::MOTIF_SANS_ANNEE))
        ->toBe("aucune année universitaire n'est en cours");
});
