<?php

use App\Domain\Care\Tickets\Enums\StatutTicket;
use App\Domain\Care\Tickets\Enums\TypeEvenement;
use App\Domain\Care\Tickets\Models\SupportTicket;
use App\Models\TenantDeployment;
use Tests\Feature\Care\Support;

beforeEach(function () {
    $this->ecole = Support::instance('presentation');
    $this->jeton = Support::jeton($this->ecole);
});

function soumettre($test, array $donnees, string $cle = 'cle-idempotence-0001', ?string $jeton = null)
{
    return $test->withToken($jeton ?? $test->jeton)
        ->withHeader('Idempotency-Key', $cle)
        ->postJson('/api/v1/support/tickets', $donnees);
}

it('cree une demande a trier, avec une reference lisible', function () {
    $r = soumettre($this, Support::soumission())->assertCreated()
        ->assertHeader('Idempotent-Replayed', 'false')
        ->assertJsonPath('statut.code', 'RECU');

    expect($r->json('reference'))->toMatch('/^KC-\d{4}-\d{6}$/');

    $ticket = SupportTicket::firstOrFail();
    expect($ticket->status)->toBe(StatutTicket::TriagePending)
        ->and($ticket->tenant_id)->toBe($this->ecole->id)
        ->and($ticket->title)->toStartWith('La moyenne de la classe');
});

it('journalise la creation, le contexte et le passage au tri', function () {
    soumettre($this, Support::soumission());

    expect(SupportTicket::firstOrFail()->events->pluck('type')->all())->toBe([
        TypeEvenement::TicketCree, TypeEvenement::ContexteJoint, TypeEvenement::StatutChange,
    ]);
});

it('pose la version deployee depuis le Master, pas depuis la requete', function () {
    $deploiement = TenantDeployment::create([
        'tenant_id' => $this->ecole->id, 'git_branch' => 'presentation', 'git_commit_hash' => str_repeat('b', 40),
        'status' => 'success', 'started_at' => now()->subHour(), 'completed_at' => now()->subMinutes(50),
    ]);

    soumettre($this, Support::soumission(['context' => ['app_commit_sha' => str_repeat('f', 40)]]));

    $contexte = SupportTicket::firstOrFail()->context;
    expect($contexte->app_commit_sha)->toBe(str_repeat('b', 40))
        ->and($contexte->deployment_id)->toBe($deploiement->id)
        ->and($contexte->module)->toBe('notes_evaluations')
        ->and($contexte->entity_type)->toBe('evaluation')
        ->and($contexte->entity_id)->toBe(622);
});

it('retrouve la demande deja creee quand la meme cle revient', function () {
    $premiere = soumettre($this, Support::soumission())->json('reference');

    soumettre($this, Support::soumission())->assertOk()
        ->assertHeader('Idempotent-Replayed', 'true')
        ->assertJsonPath('reference', $premiere);

    expect(SupportTicket::count())->toBe(1);
});

it('retrouve la demande quand seul le contexte a change entre deux envois', function () {
    $premiere = soumettre($this, Support::soumission())->json('reference');

    soumettre($this, Support::soumission(['context' => ['viewport' => '1280x800', 'request_ids' => ['autre-requete']]]))
        ->assertOk()->assertHeader('Idempotent-Replayed', 'true')
        ->assertJsonPath('reference', $premiere);

    expect(SupportTicket::count())->toBe(1);
});

it('refuse une cle reutilisee pour un autre contenu', function () {
    soumettre($this, Support::soumission());

    soumettre($this, Support::soumission(['report' => ['description' => 'Tout autre chose, vraiment différent.']]))
        ->assertStatus(422)->assertJsonPath('error', 'idempotency_key_reused');
});

it('traite la meme cle venant de deux instances comme deux demandes', function () {
    $autre = Support::instance('hetec');

    soumettre($this, Support::soumission())->assertCreated();
    soumettre($this, Support::soumission(), jeton: Support::jeton($autre))->assertCreated();

    expect(SupportTicket::pluck('tenant_id')->sort()->values()->all())->toBe([$this->ecole->id, $autre->id]);
});

it('exige une cle d idempotence', function () {
    $this->withToken($this->jeton)->postJson('/api/v1/support/tickets', Support::soumission())
        ->assertStatus(400)->assertJsonPath('error', 'idempotency_key_required');
});

it('ignore toute instance nommee dans le corps', function () {
    $autre = Support::instance('hetec');

    soumettre($this, Support::soumission(['tenant_id' => $autre->id, 'tenant_code' => 'hetec']))->assertCreated();

    expect(SupportTicket::firstOrFail()->tenant_id)->toBe($this->ecole->id);
});

it('ecarte les cles de contexte hors liste blanche', function () {
    soumettre($this, Support::soumission(['context' => ['extras' => [
        'semestre' => 1, 'cookie' => 'secret', 'etat_affiche' => 'NO_EVALUATION', 'html' => '<div>…</div>',
    ]]]))->assertCreated();

    expect(SupportTicket::firstOrFail()->context->extras)->toBe(['semestre' => 1, 'etat_affiche' => 'NO_EVALUATION']);
});

it('journalise les extras ecartes par leur cle, jamais par leur valeur', function () {
    \Illuminate\Support\Facades\Log::spy();

    soumettre($this, Support::soumission(['context' => ['extras' => [
        'semestre' => ['imbrique'], 'cookie' => 'secret',
    ]]]))->assertCreated();

    \Illuminate\Support\Facades\Log::shouldHaveReceived('warning')->withArgs(fn ($m, $c) => $m === 'care.contexte.extras_ecartes'
        && $c['cles'] === ['semestre', 'cookie'] && ! str_contains(json_encode($c), 'secret'));
});

it('valide la soumission', function (array $surcharge, string $champ) {
    soumettre($this, Support::soumission($surcharge))->assertStatus(422)->assertJsonValidationErrors($champ);
})->with([
    'description trop courte' => [['report' => ['description' => 'court']], 'report.description'],
    'categorie inconnue' => [['report' => ['category' => 'BUG']], 'report.category'],
    'type d entite hors liste' => [['context' => ['entity' => ['type' => 'user', 'id' => 1]]], 'context.entity.type'],
    'rapporteur absent' => [['reporter' => ['external_id' => 0]], 'reporter.external_id'],
]);
