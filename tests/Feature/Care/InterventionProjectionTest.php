<?php

use App\Domain\Care\Tickets\Actions\PreparerIntervention;
use App\Domain\Care\Tickets\Models\SupportTicket;
use App\Models\User;
use Tests\Feature\Care\Support;

beforeEach(function () {
    config(['care.interventions.routing_preview_enabled' => true]);
    $this->ecole = Support::instance('presentation');
    $this->autre = Support::instance('uic');
    $this->jeton = Support::jeton($this->ecole);
    $this->jetonAutre = Support::jeton($this->autre);

    $this->withToken($this->jeton)->withHeader('Idempotency-Key', 'cle-tk-intervention-p')
        ->postJson('/api/v1/support/tickets', Support::soumission())->assertCreated();
    $this->ticket = SupportTicket::firstOrFail();
    $this->support = User::create([
        'name' => 'Support', 'email' => 'support-intervention@klassci.com',
        'password' => 'x', 'role' => 'support', 'is_active' => true,
    ]);
    app(PreparerIntervention::class)->executer(
        $this->ticket, $this->support, 'intervention-001', 'Vérifier inscription',
        'Vérifier si la demande est traitée et joindre la preuve de validation.',
        'inscriptions.validate',
    );
});

it('ne fournit que les metadonnees de routage dans la bonne instance', function () {
    $res = $this->withToken($this->jeton)
        ->getJson('/api/v1/support/interventions')->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.permission_requise', 'inscriptions.validate')
        ->assertJsonPath('data.0.reference', $this->ticket->reference);

    expect(json_encode($res->json()))->not->toContain('Vérifier si la demande')
        ->not->toContain($this->ticket->description)
        ->not->toContain('created_by_admin_id')
        ->not->toContain('reporter_email_snapshot');
});

it('ne devoile aucune intervention a une autre instance', function () {
    $this->withToken($this->jetonAutre)
        ->getJson('/api/v1/support/interventions')
        ->assertOk()->assertJsonCount(0, 'data');
});

it('exclut un ticket restreint meme lorsqu une intervention est en attente', function () {
    $this->ticket->forceFill(['is_security_restricted' => true])->save();

    $this->withToken($this->jeton)
        ->getJson('/api/v1/support/interventions')
        ->assertOk()->assertJsonCount(0, 'data');
});

it('refuse les identifiants d instance sans portee de lecture', function () {
    $sans = Support::jeton($this->ecole, ['support:create']);
    $this->withToken($sans)->getJson('/api/v1/support/interventions')->assertForbidden();
});
