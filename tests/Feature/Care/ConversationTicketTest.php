<?php

use App\Domain\Care\Tickets\Enums\StatutTicket;
use App\Domain\Care\Tickets\Enums\TypeEvenement;
use App\Domain\Care\Tickets\Models\SupportTicket;
use App\Domain\Care\Tickets\Services\Acteur;
use App\Domain\Care\Tickets\Services\TicketStateMachine;
use Tests\Feature\Care\Support;

/*
 * L'ecole repond sur sa demande. Ce qui compte : elle ne repond que la ou elle
 * peut lire, un renvoi ne double pas le message, et repondre rend la main au support.
 */

beforeEach(function () {
    $this->ecole = Support::instance('presentation');
    $this->jeton = Support::jeton($this->ecole, ['support:create', 'support:read', 'support:update']);
    $this->reference = $this->withToken($this->jeton)->withHeader('Idempotency-Key', 'cle-conv-0001')
        ->postJson('/api/v1/support/tickets', Support::soumission())->json('reference');
    $this->ticket = SupportTicket::where('reference', $this->reference)->firstOrFail();
});

function repondreEcole($test, string $ref, array $donnees = [], string $cle = 'rep-cle-00001', ?string $jeton = null, string $requete = 'reporter=42')
{
    return $test->withToken($jeton ?? $test->jeton)->withHeader('Idempotency-Key', $cle)
        ->postJson("/api/v1/support/tickets/{$ref}/messages?{$requete}", $donnees + ['body' => 'En 2A BTS, semestre 1.', 'author_name' => 'Awa Koné']);
}

it('publie la reponse de l ecole dans la conversation et le journal', function () {
    repondreEcole($this, $this->reference)->assertCreated()
        ->assertHeader('Idempotent-Replayed', 'false')
        ->assertJsonPath('messages.0.auteur', 'ECOLE')
        ->assertJsonPath('messages.0.nom', 'Awa Koné')
        ->assertJsonPath('messages.0.corps', 'En 2A BTS, semestre 1.');

    expect($this->ticket->events()->where('type', TypeEvenement::ReponseClient->value)->count())->toBe(1);
});

it('ramene une demande en attente de l ecole vers le support', function () {
    $etats = app(TicketStateMachine::class);
    $etats->franchir($this->ticket, StatutTicket::WaitingCustomer, Acteur::systeme());
    $this->withToken($this->jeton)->getJson("/api/v1/support/tickets/{$this->reference}?reporter=42")
        ->assertJsonPath('statut.code', 'ACTION_REQUISE');

    repondreEcole($this, $this->reference)->assertCreated()->assertJsonPath('statut.code', 'EN_ANALYSE');

    expect($this->ticket->fresh()->status)->toBe(StatutTicket::WaitingSupport);
});

it('rouvre une demande resolue, refuse une demande close', function () {
    $etats = app(TicketStateMachine::class);
    $etats->franchir($this->ticket, StatutTicket::Resolved, Acteur::systeme());
    repondreEcole($this, $this->reference)->assertCreated();
    expect($this->ticket->fresh()->status)->toBe(StatutTicket::Triaged);

    $etats->franchir($this->ticket->fresh(), StatutTicket::Rejected, Acteur::systeme(), 'Hors périmètre du support.');
    repondreEcole($this, $this->reference, cle: 'rep-cle-00002')->assertStatus(409)->assertJsonPath('error', 'ticket_closed');
});

it('ne double pas un message renvoye avec la meme cle', function () {
    repondreEcole($this, $this->reference)->assertCreated();
    repondreEcole($this, $this->reference)->assertOk()->assertHeader('Idempotent-Replayed', 'true');
    repondreEcole($this, $this->reference, ['body' => 'Autre texte.'])->assertStatus(422)->assertJsonPath('error', 'idempotency_key_reused');

    expect($this->ticket->messages()->count())->toBe(1);
});

it('ne repond que la ou l ecole peut lire', function () {
    $autre = Support::instance('hetec');
    $jetonAutre = Support::jeton($autre, ['support:update', 'support:read']);

    repondreEcole($this, $this->reference, jeton: $jetonAutre)->assertNotFound();
    repondreEcole($this, $this->reference, requete: 'reporter=7')->assertNotFound();
    repondreEcole($this, $this->reference, requete: 'reporter=7&scope=school')->assertCreated();
});

it('exige la portee support:update et une cle', function () {
    $lecture = Support::jeton($this->ecole);

    repondreEcole($this, $this->reference, jeton: $lecture)->assertForbidden()->assertJsonPath('error', 'insufficient_scope');
    $this->flushHeaders()->withToken($this->jeton)->postJson("/api/v1/support/tickets/{$this->reference}/messages?reporter=42", ['body' => 'Sans clé.'])
        ->assertStatus(400);
});

it('annonce ses portees a l instance', function () {
    $this->withToken($this->jeton)->getJson('/api/v1/support/bootstrap')
        ->assertJsonPath('portees', ['support:create', 'support:read', 'support:update'])
        ->assertJsonPath('limites.reponse_min', 2);
});
