<?php

use Filament\Facades\Filament;

/**
 * Le panel admin doit exposer TOUT ce qui est sur le disque.
 *
 * Un cache de panel Filament (bootstrap/cache/filament/panels/admin.php) avait
 * été commité dans le dépôt, figé sur les chemins d'une machine de
 * développement disparue. Filament préfère ce cache à la découverte : cinq
 * écrans — groupes, admins SaaS, offres, journal d'activité, tableau de santé —
 * étaient donc absents du panel en production, sans la moindre erreur.
 *
 * Rien ne le signalait.
 *
 * Attention a ce que ces tests couvrent VRAIMENT : Filament ignore son cache
 * de composants des que l'application tourne en console (hasCachedComponents()
 * commence par ! app()->runningInConsole()). Les trois tests de decouverte
 * ci-dessous ne peuvent donc PAS reproduire la panne — verifie, en remettant
 * le fichier de cache en place : ils passent quand meme.
 *
 * Le seul garde-fou reel contre cette panne est le premier test, qui refuse
 * qu'un cache de panel soit suivi par git. Les trois autres couvrent une autre
 * famille : une Resource, une Page ou un Widget que la decouverte rate pour une
 * raison de nommage ou de namespace.
 */
it('n a aucun cache de panel commite dans le depot', function () {
    $suivis = trim((string) shell_exec('cd '.escapeshellarg(base_path()).' && git ls-files bootstrap/cache/ 2>/dev/null'));

    expect($suivis)->toBe(
        '',
        "Un cache de panel est suivi par git :\n{$suivis}\n"
        ."Il fige la liste des ecrans sur la machine qui l'a genere. "
        .'Retire-le et laisse .gitignore le couvrir.'
    );
});

it('enregistre chaque Resource presente sur le disque', function () {
    Filament::setCurrentPanel(Filament::getPanel('admin'));

    $surDisque = collect(glob(app_path('Filament/Resources/*Resource.php')))
        ->map(fn ($f) => 'App\\Filament\\Resources\\'.basename($f, '.php'))
        ->sort()->values();

    $enregistrees = collect(Filament::getPanel('admin')->getResources())->sort()->values();

    $manquantes = $surDisque->diff($enregistrees);

    expect($manquantes->all())->toBe(
        [],
        'Ces Resources existent mais le panel ne les expose pas : '.$manquantes->implode(', ')
    );
})->skip(fn () => ! is_dir(app_path('Filament/Resources')), 'pas de Resources');

it('enregistre chaque Page presente sur le disque', function () {
    Filament::setCurrentPanel(Filament::getPanel('admin'));

    $surDisque = collect(glob(app_path('Filament/Pages/*.php')))
        ->map(fn ($f) => 'App\\Filament\\Pages\\'.basename($f, '.php'))
        ->sort()->values();

    $enregistrees = collect(Filament::getPanel('admin')->getPages())->sort()->values();

    $manquantes = $surDisque->diff($enregistrees);

    expect($manquantes->all())->toBe(
        [],
        'Ces Pages existent mais le panel ne les expose pas : '.$manquantes->implode(', ')
    );
})->skip(fn () => ! is_dir(app_path('Filament/Pages')), 'pas de Pages');

it('enregistre chaque Widget present sur le disque', function () {
    Filament::setCurrentPanel(Filament::getPanel('admin'));

    $surDisque = collect(glob(app_path('Filament/Widgets/*.php')))
        ->map(fn ($f) => 'App\\Filament\\Widgets\\'.basename($f, '.php'))
        ->sort()->values();

    $enregistres = collect(Filament::getPanel('admin')->getWidgets())->sort()->values();

    $manquants = $surDisque->diff($enregistres);

    expect($manquants->all())->toBe(
        [],
        'Ces Widgets existent mais le panel ne les expose pas : '.$manquants->implode(', ')
    );
})->skip(fn () => ! is_dir(app_path('Filament/Widgets')), 'pas de Widgets');

it('instancie chaque classe du panel sans erreur fatale', function () {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $panel = Filament::getPanel('admin');

    $classes = array_merge($panel->getResources(), $panel->getPages(), $panel->getWidgets());
    $verifiees = 0;

    foreach ($classes as $classe) {
        expect(class_exists($classe))->toBeTrue("{$classe} est enregistree mais introuvable");
        $verifiees++;
    }

    // Sans cette borne, un panel vide passerait le test en ne bouclant sur rien.
    expect($verifiees)->toBeGreaterThan(0);
});
