<?php

use App\Domain\Care\Tickets\Models\SupportTicket;
use App\Domain\Cli\CapacitesCli;
use App\Domain\Deploiement\DemanderDeploiement;
use App\Domain\Deploiement\DeploiementDejaDemande;
use App\Models\Tenant;
use App\Models\TenantActivityLog;
use App\Models\TenantDeployment;
use App\Models\User;
use Illuminate\Foundation\Console\QueuedCommand;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Care\Support as CareSupport;

/**
 * L'API du CLI de l'équipe (klassci admin:*), appelée avec de vrais jetons.
 */

function membre(string $role, bool $actif = true): User
{
    return User::create([
        'name' => ucfirst($role) . ' Test',
        'email' => $role . uniqid() . '@klassci.test',
        'password' => 'mot-de-passe-test',
        'role' => $role,
        'is_active' => $actif,
    ]);
}

function jetonDe(User $membre, ?array $capacites = null): string
{
    return $membre->createToken('cli:test', $capacites ?? CapacitesCli::duRole($membre->role))->plainTextToken;
}

function ecoleCli(string $code = 'islg'): Tenant
{
    return Tenant::create([
        'code' => $code, 'name' => strtoupper($code), 'subdomain' => $code,
        'database_name' => "klassci_{$code}",
        'database_credentials' => ['host' => '127.0.0.1', 'port' => 1, 'username' => 'secret-user', 'password' => 'secret-pass'],
        'git_branch' => 'presentation', 'status' => 'active', 'plan' => 'elite',
        'api_token' => str_repeat('f', 64),
        'subscription_end_date' => now()->addDays(4),
    ]);
}

/** Sanctum garde l'utilisateur authentifié d'une requête à l'autre d'un même test. */
function changerDeMembre(): void
{
    app('auth')->forgetGuards();
}

beforeEach(function () {
    Queue::fake();
    // En production, un utilisateur MySQL en lecture seule. Ici, une connexion
    // nommée à part (la principale est refusée) qui partage la base de test.
    config([
        'database.connections.lecture_test' => config('database.connections.' . config('database.default')),
        'klassci.cli_sql_connexion' => 'lecture_test',
    ]);
    DB::connection('lecture_test')->setPdo(DB::connection()->getPdo());
});

it('refuse tout appel sans jeton', function () {
    $this->getJson('/api/cli/ecoles')->assertUnauthorized();
});

it('liste les écoles sans jamais faire sortir leurs identifiants', function () {
    ecoleCli();

    $reponse = $this->withToken(jetonDe(membre('billing')))->getJson('/api/cli/ecoles/islg')->assertOk();

    expect($reponse->json('data.jours_restants'))->toBe(4)
        ->and($reponse->json('data.jeton_api_emis'))->toBeTrue()
        ->and($reponse->getContent())->not->toContain('secret-pass')->not->toContain(str_repeat('f', 64));
});

it('laisse le support déployer, met le déploiement en file au nom du membre et le journalise', function () {
    $ecole = ecoleCli();
    $support = membre('support');

    $this->withToken(jetonDe($support))
        ->postJson('/api/cli/ecoles/islg/deploiements', ['branche' => 'presentation'])
        ->assertStatus(202);

    Queue::assertPushed(QueuedCommand::class, fn (QueuedCommand $job) => str_contains(serialize($job), 'tenant:deploy')
        && str_contains(serialize($job), (string) $support->id));
    expect(TenantActivityLog::where('tenant_id', $ecole->id)->where('action', 'cli_deploy_requested')->value('performed_by_user_id'))
        ->toBe($support->id);
});

it('refuse un second déploiement tant que le premier attend dans la file', function () {
    ecoleCli();
    $jeton = jetonDe(membre('support'));

    $this->withToken($jeton)->postJson('/api/cli/ecoles/islg/deploiements')->assertStatus(202);
    $this->withToken($jeton)->postJson('/api/cli/ecoles/islg/deploiements')->assertStatus(409);
});

it('accepte une relance dès que le déploiement précédent a démarré puis fini', function () {
    $ecole = ecoleCli();
    $jeton = jetonDe(membre('support'));
    $this->withToken($jeton)->postJson('/api/cli/ecoles/islg/deploiements')->assertStatus(202);

    TenantDeployment::create([
        'tenant_id' => $ecole->id, 'git_branch' => 'presentation', 'status' => 'failed',
        'started_at' => now(), 'completed_at' => now(),
    ]);

    $this->withToken($jeton)->postJson('/api/cli/ecoles/islg/deploiements')->assertStatus(202);
});

it('refuse le déploiement au rôle facturation', function () {
    ecoleCli();

    $this->withToken(jetonDe(membre('billing')))->postJson('/api/cli/ecoles/islg/deploiements')->assertForbidden();
    Queue::assertNothingPushed();
});

it('retire ses droits à un membre rétrogradé, même avec un ancien jeton', function () {
    ecoleCli();
    $membre = membre('support');
    $jeton = jetonDe($membre);
    $membre->forceFill(['role' => 'billing'])->save();

    $this->withToken($jeton)->postJson('/api/cli/ecoles/islg/deploiements')->assertForbidden();
});

it('refuse un membre désactivé', function () {
    $membre = membre('super_admin');
    $jeton = jetonDe($membre);
    $membre->forceFill(['is_active' => false])->save();

    $this->withToken($jeton)->getJson('/api/cli/ecoles')->assertForbidden();
});

it('refuse une branche piégée', function () {
    ecoleCli();

    $this->withToken(jetonDe(membre('super_admin')))
        ->postJson('/api/cli/ecoles/islg/deploiements', ['branche' => 'main;id'])
        ->assertStatus(422);
    Queue::assertNothingPushed();
});

it('ouvre le SQL au super_admin seulement', function () {
    ecoleCli();

    $this->withToken(jetonDe(membre('support')))->postJson('/api/cli/sql', ['requete' => 'SELECT 1'])->assertForbidden();
    changerDeMembre();

    $this->withToken(jetonDe(membre('super_admin')))
        ->postJson('/api/cli/sql', ['requete' => 'SELECT code FROM tenants'])
        ->assertOk()
        ->assertJsonPath('data.lignes.0.code', 'islg');
});

it('garde la lecture SQL fermée sans connexion dédiée, jamais sur la principale', function (mixed $reglage) {
    ecoleCli();
    config(['klassci.cli_sql_connexion' => is_callable($reglage) ? $reglage() : $reglage]);

    $this->withToken(jetonDe(membre('super_admin')))
        ->postJson('/api/cli/sql', ['requete' => 'SELECT code FROM tenants'])
        ->assertStatus(503)
        ->assertJsonPath('success', false);
})->with([
    'non renseignée' => [null],
    'vide' => [''],
    'false dans le .env' => [false],
    'zéro' => ['0'],
    'connexion principale' => [fn () => config('database.default')],
    'connexion inconnue' => ['nulle-part'],
]);

it('refuse un utilisateur « lecture » identique à celui de la connexion principale', function () {
    ecoleCli();
    // La connexion principale des tests ne change pas (le nettoyage de la base
    // en dépend) : on lui prête seulement un nom d'utilisateur.
    config([
        'database.connections.' . config('database.default') . '.username' => 'root',
        'database.connections.lecture_copiee' => ['driver' => 'mysql', 'username' => 'root'],
        'klassci.cli_sql_connexion' => 'lecture_copiee',
    ]);

    expect(fn () => app(\App\Domain\Cli\LectureSql::class)->executer('SELECT 1'))
        ->toThrow(\App\Domain\Cli\LectureSqlFermee::class);
});

it('masque les colonnes sensibles d\'un SELECT *', function () {
    ecoleCli();

    $reponse = $this->withToken(jetonDe(membre('super_admin')))
        ->postJson('/api/cli/sql', ['requete' => 'SELECT * FROM tenants'])
        ->assertOk();

    expect($reponse->json('data.lignes.0.database_credentials'))->toBe('***')
        ->and($reponse->getContent())->not->toContain('secret-pass');
});

it('refuse de nommer une colonne sensible, même sous un alias', function (string $requete) {
    ecoleCli();

    $reponse = $this->withToken(jetonDe(membre('super_admin')))
        ->postJson('/api/cli/sql', ['requete' => $requete])
        ->assertStatus(422);

    expect($reponse->getContent())->not->toContain('secret-pass');
})->with([
    'alias' => 'SELECT database_credentials AS x FROM tenants',
    'alias sans AS' => 'SELECT code, api_token t FROM tenants',
    'accents graves' => 'SELECT `database_credentials` FROM tenants',
    'dans une expression' => 'SELECT CONCAT(code, database_credentials) FROM tenants',
    'table des jetons' => 'SELECT * FROM personal_access_tokens',
]);

it('refuse toute écriture, même déguisée en lecture', function (string $requete) {
    ecoleCli();

    $this->withToken(jetonDe(membre('super_admin')))
        ->postJson('/api/cli/sql', ['requete' => $requete])
        ->assertStatus(422);

    expect(Tenant::where('code', 'islg')->exists())->toBeTrue();
})->with([
    'suppression directe' => 'DELETE FROM tenants',
    'deux instructions' => 'SELECT 1; DELETE FROM tenants',
    // Passe le filtre (commence par WITH) : c'est la base, en lecture seule, qui refuse.
    'écriture après un WITH' => 'WITH x AS (SELECT 1) DELETE FROM tenants',
    'motif interdit caché par un commentaire' => 'SELECT code INTO/**/OUTFILE \'/tmp/x\' FROM tenants',
    'commentaire de fin' => 'SELECT code FROM tenants -- ',
]);

it('cache les demandes restreintes au support, pas au super_admin', function () {
    $ecole = CareSupport::instance('presentation');
    $jetonEcole = CareSupport::jeton($ecole);
    foreach (['cle-cli-0001', 'cle-cli-0002'] as $cle) {
        $this->withToken($jetonEcole)->withHeader('Idempotency-Key', $cle)
            ->postJson('/api/v1/support/tickets', CareSupport::soumission())->assertCreated();
    }
    SupportTicket::latest('id')->firstOrFail()->forceFill(['is_security_restricted' => true])->save();
    changerDeMembre();

    expect($this->withToken(jetonDe(membre('support')))->getJson('/api/cli/demandes')->json('data'))->toHaveCount(1);
    changerDeMembre();
    expect($this->withToken(jetonDe(membre('super_admin')))->getJson('/api/cli/demandes')->json('data'))->toHaveCount(2);
});

it('émet un jeton qui porte les capacités du rôle', function () {
    $membre = membre('support');

    $this->artisan('cli:jeton', ['email' => $membre->email, '--nom' => 'portable'])->assertSuccessful();

    expect($membre->tokens()->first()->abilities)->toBe(CapacitesCli::duRole('support'))
        ->and($membre->tokens()->first()->abilities)->not->toContain(CapacitesCli::SQL)
        ->and($membre->tokens()->first()->expires_at?->isFuture())->toBeTrue();
});

it('partage la garde de déploiement avec le webhook : pas deux déploiements à la suite', function () {
    ecoleCli();
    config(['app.deploy_webhook_token' => 'jeton-webhook']);

    $this->postJson('/api/deploy', ['tenant_code' => 'islg'], ['Authorization' => 'Bearer jeton-webhook'])->assertStatus(202);
    $this->withToken(jetonDe(membre('support')))->postJson('/api/cli/ecoles/islg/deploiements')->assertStatus(409);

    Queue::assertPushed(QueuedCommand::class, 1);
});

it('refuse la demande tant qu\'une autre tient le verrou de l\'école', function () {
    $ecole = ecoleCli();
    $verrou = Cache::lock('deploiement:verrou:islg', 10);
    $verrou->get();

    expect(fn () => app(DemanderDeploiement::class)->demander($ecole))->toThrow(DeploiementDejaDemande::class);
    Queue::assertNothingPushed();

    $verrou->release();
});

it('caviarde un jeton glissé dans une URL git de la sortie d\'un déploiement', function () {
    $ecole = ecoleCli();
    $deploiement = TenantDeployment::create([
        'tenant_id' => $ecole->id, 'git_branch' => 'presentation', 'status' => 'failed',
        'started_at' => now(), 'error_message' => 'fatal: https://ghp_secret123@github.com/x/y.git introuvable',
        'deployment_log' => [['step' => 'git_pull', 'status' => 'failed', 'output' => 'remote: https://ghp_secret123@github.com/x/y.git']],
    ]);

    $reponse = $this->withToken(jetonDe(membre('billing')))->getJson("/api/cli/deploiements/{$deploiement->id}")->assertOk();

    expect($reponse->getContent())->not->toContain('ghp_secret123')
        ->and($reponse->json('data.etapes.0.sortie'))->toContain('https://***@github.com');
});

it('met la vérification de santé en file au lieu de la faire dans la requête', function () {
    ecoleCli();

    $this->withToken(jetonDe(membre('support')))->postJson('/api/cli/ecoles/islg/sante')->assertStatus(202);

    Queue::assertPushed(QueuedCommand::class, fn (QueuedCommand $job) => str_contains(serialize($job), 'tenant:health-check'));
});
