<?php

/**
 * La posture de l'audit de sécurité, verrouillée.
 *
 * Un job de CI se désactive d'une ligne, et personne ne s'en aperçoit : c'est
 * déjà arrivé ici, la CI épinglée sur une version de PHP que plus personne
 * n'exécutait. Ces tests ne vérifient pas que l'audit trouve quelque chose —
 * cela dépend du monde, pas du dépôt — mais que les décisions prises sont
 * toujours celles qui s'appliquent.
 */
function workflowSecurite(): string
{
    return file_get_contents(base_path('.github/workflows/securite.yml'));
}

it('audite le verrou des dépendances à chaque proposition de fusion', function () {
    // `--locked` : on audite ce qui sera déployé, pas ce que le runner a
    // résolu ce matin.
    expect(workflowSecurite())
        ->toContain('composer audit --locked')
        ->toContain('pull_request:');
});

it('repasse chaque semaine, parce qu un avis paraît après la fusion', function () {
    // Le code n'a pas changé, mais le monde si. Sans ce passage, un avis
    // publié le lendemain d'une fusion ne serait jamais vu.
    expect(workflowSecurite())
        ->toContain('schedule:')
        ->toContain("cron: '0 6 * * 1'");
});

it('vit dans son propre fichier, séparé des tests', function () {
    // Deux questions différentes, deux cadences, deux signaux. Et surtout :
    // protéger la branche sur « Tests » ne doit pas dépendre de la dette de
    // dépendances, ni l'inverse.
    expect(file_get_contents(base_path('.github/workflows/tests.yml')))
        ->not->toContain('composer audit')
        ->not->toContain('schedule:');
});

it('ne bloque que sur ce qui part réellement en production', function () {
    // Un avis sur PHPUnit qui empêche de livrer un correctif de production
    // serait la meilleure façon d'apprendre à passer outre — et le jour où un
    // avis critique arrive en production, on passerait outre aussi. Le
    // rapport, lui, couvre tout : une bibliothèque de test compromise reste un
    // risque pour le poste et pour la CI.
    $source = workflowSecurite();

    expect($source)
        ->toContain('--no-dev')
        ->toContain('bloquants=$(jq')
        // …et parmi ce qui est déployé, seules les gravités hautes bloquent.
        ->toContain('$s == "high" or $s == "critical"')
        // La gravité arrive parfois en majuscules selon la source de l'avis.
        ->toContain('ascii_downcase');
});

it('rend son rapport lisible ailleurs que dans un navigateur', function () {
    // Écrit dans le seul résumé de job, le tableau échappe à l'API, aux outils
    // et à quiconque lit la CI autrement qu'à l'écran : on saurait QU'IL y a
    // des avis, pas LESQUELS.
    expect(workflowSecurite())
        ->toContain('tee -a "$GITHUB_STEP_SUMMARY"');
});

it('ne prend pas une panne réseau pour un verrou sain', function () {
    // Un audit non concluant annoncé comme vert serait pire que pas d'audit.
    expect(workflowSecurite())
        ->toContain('audit non concluant')
        ->toContain('::warning::');
});

it('signale les paquets abandonnés sans bloquer sur eux', function () {
    expect(workflowSecurite())->toContain('--abandoned=report');
});

it('offre la même vérification en une commande sur le poste', function () {
    $composer = json_decode(file_get_contents(base_path('composer.json')), true);

    expect($composer['scripts'])->toHaveKey('securite')
        ->and(implode(' ', (array) $composer['scripts']['securite']))
            ->toContain('audit --locked');
});

it('confie la réparation à Dependabot, pas à la mémoire de quelqu un', function () {
    // L'audit signale, il ne répare pas. Sans ce fichier, la réparation
    // dépendait de quelqu'un qui pense à lancer `composer update` — et c'est
    // précisément ce qui n'est pas arrivé : le verrou portait 48 avis, dont 14
    // de gravité haute ou critique.
    //
    // Lu comme du texte : ni l'extension `yaml` ni `symfony/yaml` ne sont
    // installées, et en ajouter une pour lire neuf lignes coûterait plus que
    // ce que ce test protège.
    $dependabot = file_get_contents(base_path('.github/dependabot.yml'));

    expect($dependabot)
        ->toContain('package-ecosystem: composer')
        // Les actions du workflow vieillissent aussi.
        ->toContain('package-ecosystem: github-actions')
        // Groupées : une proposition par semaine se relit, dix par jour se
        // ferment sans lire.
        ->toContain('applies-to: security-updates');
});
