<?php

use App\Domain\Care\Tickets\Actions\RepondreTicket;
use App\Domain\Care\Tickets\Enums\VisibiliteMessage;
use App\Domain\Care\Tickets\Models\SupportTicket;
use App\Models\User;
use Tests\Feature\Care\Support;

/*
 * Ce qu'une ecole lit de ses demandes. Chaque test ici est une fuite possible :
 * vers une autre ecole, vers un autre utilisateur, ou vers une note interne.
 */

beforeEach(function () {
    $this->ecole = Support::instance('presentation');
    $this->autre = Support::instance('hetec');
    $this->jeton = Support::jeton($this->ecole);
    $this->jetonAutre = Support::jeton($this->autre);

    $creer = fn ($jeton, $rapporteur, $cle) => $this->withToken($jeton)->withHeader('Idempotency-Key', $cle)
        ->postJson('/api/v1/support/tickets', Support::soumission(['reporter' => ['external_id' => $rapporteur]]))
        ->json('reference');

    $this->mienne = $creer($this->jeton, 42, 'cle-a-00000001');
    $this->collegue = $creer($this->jeton, 7, 'cle-b-00000001');
    $this->ailleurs = $creer($this->jetonAutre, 42, 'cle-c-00000001');

    $this->staff = User::create(['name' => 'Support', 'email' => 's@klassci.com', 'password' => 'x', 'role' => 'support', 'is_active' => true]);
});

it('ne liste que les demandes du rapporteur par defaut', function () {
    $this->withToken($this->jeton)->getJson('/api/v1/support/tickets?reporter=42')
        ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.reference', $this->mienne);
});

it('liste toute l ecole en scope school, jamais une autre ecole', function () {
    $refs = collect($this->withToken($this->jeton)->getJson('/api/v1/support/tickets?reporter=42&scope=school')->json('data'))
        ->pluck('reference')->all();

    expect($refs)->toContain($this->mienne, $this->collegue)->not->toContain($this->ailleurs);
});

it('rend 404 pour la demande d une autre ecole, meme avec la reference exacte', function () {
    $this->withToken($this->jeton)->getJson("/api/v1/support/tickets/{$this->ailleurs}?reporter=42&scope=school")
        ->assertNotFound();
});

it('rend 404 pour la demande d un collegue hors scope school', function () {
    $this->withToken($this->jeton)->getJson("/api/v1/support/tickets/{$this->collegue}?reporter=42")->assertNotFound();
});

it('ne montre que les messages publics, et jamais les champs internes', function () {
    $ticket = SupportTicket::where('reference', $this->mienne)->firstOrFail();
    app(RepondreTicket::class)->executer($ticket, $this->staff, 'Nous regardons, merci.', VisibiliteMessage::PublicClient);
    app(RepondreTicket::class)->executer($ticket, $this->staff, 'Probable régression du 12/09.', VisibiliteMessage::InterneSupport);

    $r = $this->withToken($this->jeton)->getJson("/api/v1/support/tickets/{$this->mienne}?reporter=42")->assertOk();

    expect($r->json('messages'))->toHaveCount(1)
        ->and($r->json('messages.0.corps'))->toBe('Nous regardons, merci.')
        ->and($r->json('messages.0.nom'))->toBe('Support KLASSCI')
        ->and(json_encode($r->json()))->not->toContain('régression')
        ->and($r->json())->not->toHaveKeys(['status', 'severity', 'internal_category', 'tenant_id', 'idempotency_key']);
});

it('masque les demandes restreintes pour raison de securite', function () {
    SupportTicket::where('reference', $this->mienne)->update(['is_security_restricted' => true]);

    $this->withToken($this->jeton)->getJson("/api/v1/support/tickets/{$this->mienne}?reporter=42")->assertNotFound();
});

it('exige le rapporteur sur chaque lecture', function () {
    $this->withToken($this->jeton)->getJson('/api/v1/support/tickets')->assertStatus(422);
});
