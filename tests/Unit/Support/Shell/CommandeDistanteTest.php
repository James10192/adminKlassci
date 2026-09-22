<?php

use App\Support\Shell\CommandeDistante;

it('cite chaque mot pour que le shell distant ne puisse rien interpréter', function () {
    $ligne = CommandeDistante::construire("/home/x/public_html/a'; rm -rf /; '", ['git', 'clone', '-b', 'main;id', '$(id)']);

    expect($ligne)->toBe(
        "cd '/home/x/public_html/a'\\''; rm -rf /; '\\''' && 'git' 'clone' '-b' 'main;id' '\$(id)'"
    );
});

it('rend une commande que sh relit mot pour mot', function () {
    $dossier = sys_get_temp_dir();
    $ligne = CommandeDistante::construire($dossier, ['printf', '%s|', 'a;b', '$(id)', "c'd"]);

    expect(shell_exec('sh -c ' . escapeshellarg($ligne)))->toBe("a;b|\$(id)|c'd|");
});
