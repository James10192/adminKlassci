<?php

/**
 * La posture de l'audit de sécurité, verrouillée.
 *
 * Un job de CI se désactive d'une ligne, et personne ne s'en aperçoit : c'est
 * déjà arrivé ici, la CI épinglée sur une version de PHP que plus personne
 * n'exécutait. Ces tests ne vérifient pas que l'audit trouve quelque chose —
 * cela dépend du monde, pas du dépôt — mais que les trois décisions prises
 * sont toujours celles qui s'appliquent.
 */
function workflowTests(): string
{
    return file_get_contents(base_path('.github/workflows/tests.yml'));
}

it('audite le verrou des dépendances à chaque proposition de fusion', function () {
    // `--locked` : on audite ce qui sera déployé, pas ce que le runner a
    // résolu ce matin.
    expect(workflowTests())
        ->toContain('composer audit --locked')
        ->toContain('pull_request:');
});

it('repasse chaque semaine, parce qu un avis paraît après la fusion', function () {
    // Le code n'a pas changé, mais le monde si. Sans ce passage, un avis
    // publié le lendemain d'une fusion ne serait jamais vu.
    expect(workflowTests())
        ->toContain('schedule:')
        ->toContain("cron: '0 6 * * 1'")
        // …et le passage hebdomadaire ne rejoue pas la suite : cela
        // n'apprendrait rien et ferait du bruit.
        ->toContain("if: github.event_name != 'schedule'");
});

it('ne bloque que sur les gravités hautes et critiques', function () {
    // Un avis moyen dans une dépendance transitive bloquerait chaque PR,
    // correctif de production compris, jusqu'à ce que quelqu'un prenne
    // l'habitude de passer outre — et le jour où un avis critique arrive, il
    // passerait outre aussi.
    $source = workflowTests();

    expect($source)
        ->toContain('$s == "high" or $s == "critical"')
        // La gravité arrive parfois en majuscules selon la source de l'avis.
        ->toContain('ascii_downcase')
        // Tous les avis restent visibles, quelle que soit leur gravité.
        ->toContain('GITHUB_STEP_SUMMARY');
});

it('ne prend pas une panne réseau pour un verrou sain', function () {
    // L'API des avis peut ne pas répondre. Le job doit le DIRE et laisser
    // passer — un audit non concluant annoncé comme vert serait pire que pas
    // d'audit du tout.
    expect(workflowTests())
        ->toContain('audit non concluant')
        ->toContain('::warning::');
});

it('signale les paquets abandonnés sans bloquer sur eux', function () {
    // Un paquet abandonné n'est pas une vulnérabilité.
    expect(workflowTests())->toContain('--abandoned=report');
});

it('offre la même vérification en une commande sur le poste', function () {
    // Le fondateur ne doit pas avoir à retrouver les options : `composer
    // securite` fait exactement ce que fait la CI.
    $composer = json_decode(file_get_contents(base_path('composer.json')), true);

    expect($composer['scripts'])->toHaveKey('securite')
        ->and(implode(' ', (array) $composer['scripts']['securite']))
            ->toContain('audit --locked');
});
