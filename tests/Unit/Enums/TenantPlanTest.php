<?php

use App\Enums\TenantPlan;

/**
 * Les offres commerciales, nommées une seule fois.
 *
 * Le libellé et la couleur d'une offre vivaient recopiés dans cinq fichiers, et
 * les copies avaient divergé : le tableau du portail groupe n'en donnait aucun
 * libellé — il affichait « elite », « free » en minuscules à côté d'une colonne
 * « Statut » traduite en « Actif » — la fiche établissement passait par
 * `ucfirst()`, qui perd l'accent d'« Élite », et la couleur se contredisait
 * d'un écran à l'autre.
 */
it('couvre les quatre offres de la colonne plan', function () {
    expect(array_column(TenantPlan::cases(), 'value'))
        ->toBe(['free', 'essentiel', 'professional', 'elite']);
});

it('écrit « Élite » avec son accent, ce que ucfirst() ne fait pas', function () {
    expect(TenantPlan::Elite->libelle())->toBe('Élite');
    expect(ucfirst('elite'))->not->toBe(TenantPlan::Elite->libelle());
});

it('ne juge aucune offre : ni alarme, ni succès', function () {
    // Un `warning` sur « Élite » dirait « attention » a propos du client qui
    // paie le plus ; un `success` sur une offre dirait qu'une autre est un
    // echec. L'echelle est neutre.
    foreach (TenantPlan::cases() as $offre) {
        expect($offre->ton())->toBeIn(['gray', 'info', 'primary']);
    }
});

it('se dégrade sur une offre inconnue plutôt que de lever', function () {
    expect(TenantPlan::libelleDe('partenaire'))->toBe('Partenaire');
    expect(TenantPlan::tonDe('partenaire'))->toBe('gray');
    expect(TenantPlan::libelleDe(null))->toBe('Sans offre');
});

it('peuple un select avec les quatre offres nommées', function () {
    expect(TenantPlan::options())->toBe([
        'free' => 'Free',
        'essentiel' => 'Essentiel',
        'professional' => 'Professional',
        'elite' => 'Élite',
    ]);
});
