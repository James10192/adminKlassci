<?php

use App\Models\TenantErrorEvent;
use Tests\Feature\Care\Support;

function incidentTechnique(array $override = []): array
{
    return array_replace([
        'request_id' => '01M63RF829V0XZBPSEV2ZQZ2QZ',
        'route_name' => 'esbtp.annonces.store',
        'exception_class' => 'Illuminate\Database\QueryException',
        'category' => 'database',
        'method' => 'POST',
        'http_status' => 500,
        'sqlstate' => '42S22',
        'db_errno' => 1054,
    ], $override);
}

it('authentifie chaque instance avec une portee de telemetrie et isole les ecoles', function () {
    $a = Support::instance('esbtp-abidjan');
    $b = Support::instance('esbtp-yakro');
    $jetonA = Support::jeton($a, ['telemetry:send']);
    $jetonB = Support::jeton($b, ['telemetry:send']);

    $this->postJson('/api/v1/support/incidents', incidentTechnique())->assertUnauthorized();
    $this->withToken(Support::jeton($a, ['support:read']))
        ->postJson('/api/v1/support/incidents', incidentTechnique())->assertForbidden();

    $this->withToken($jetonA)
        ->postJson('/api/v1/support/incidents', incidentTechnique())->assertCreated();
    $this->withToken($jetonB)
        ->postJson('/api/v1/support/incidents', incidentTechnique())->assertCreated();

    expect(TenantErrorEvent::query()->where('tenant_id', $a->id)->count())->toBe(1)
        ->and(TenantErrorEvent::query()->where('tenant_id', $b->id)->count())->toBe(1);
});

it('est idempotent pour un identifiant de requete et ne conserve aucune donnee SQL ou personnelle', function () {
    $tenant = Support::instance('esbtp-abidjan');
    $this->withToken(Support::jeton($tenant, ['telemetry:send']));
    $payload = incidentTechnique([
        'tenant_id' => 999999,
        'message' => "INSERT INTO esbtp_annonces VALUES ('secret eleve')",
        'sql' => 'INSERT INTO secrets',
        'body' => 'Contenu prive de l annonce',
        'email' => 'etudiant@exemple.test',
    ]);
    $this->postJson('/api/v1/support/incidents', $payload)->assertCreated()->assertJsonPath('replayed', false);
    $this->postJson('/api/v1/support/incidents', $payload)->assertOk()->assertJsonPath('replayed', true);
    $event = TenantErrorEvent::firstOrFail();
    expect(TenantErrorEvent::count())->toBe(1)
        ->and($event->tenant_id)->toBe($tenant->id)
        ->and($event->sqlstate)->toBe('42S22')
        ->and(json_encode($event->getAttributes()))->not->toContain('INSERT INTO')
        ->not->toContain('Contenu prive')
        ->not->toContain('etudiant@exemple.test');
});

it('refuse les traces, les longues donnees et les codes invalides dans les champs autorises', function () {
    $tenant = Support::instance('presentation');
    $this->withToken(Support::jeton($tenant, ['telemetry:send']));
    $this->postJson('/api/v1/support/incidents', incidentTechnique([
        'route_name' => '/esbtp/annonces/create?email=privé',
    ]))->assertUnprocessable();
    $this->postJson('/api/v1/support/incidents', incidentTechnique([
        'exception_class' => 'QueryException: INSERT INTO ...',
    ]))->assertUnprocessable();
    $this->postJson('/api/v1/support/incidents', incidentTechnique([
        'sqlstate' => 'INSERT INTO',
    ]))->assertUnprocessable();
    expect(TenantErrorEvent::count())->toBe(0);
});
