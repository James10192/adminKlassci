<?php

use App\Domain\Care\Notifications\AnnonceSlack;
use App\Domain\Care\Tickets\Enums\TypeEvenement;
use App\Domain\Care\Tickets\Models\SupportTicket;
use App\Domain\Care\Tickets\Services\Acteur;
use App\Domain\Care\Tickets\Services\Journal;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Care\Support;

/**
 * Ce qui part vers le canal Slack du service technique, et ce qui n'y part pas.
 * Les demandes sont créées par la vraie API ; l'annonce part à la fin de la
 * requête, comme en production (le noyau de test termine chaque requête).
 */

const WEBHOOK_TEST = 'https://hooks.slack.test/services/T000/B000/XXXX';

beforeEach(function () {
    $this->ecole = Support::instance('presentation');
    $this->jeton = Support::jeton($this->ecole);
    config(['care.slack.webhook' => WEBHOOK_TEST]);
    Http::fake([WEBHOOK_TEST => Http::response('ok')]);
});

function deposerDemande($test, string $cle = 'cle-slack-0001')
{
    return $test->withToken($test->jeton)
        ->withHeader('Idempotency-Key', $cle)
        ->postJson('/api/v1/support/tickets', Support::soumission());
}

it('annonce une nouvelle demande en un seul message', function () {
    deposerDemande($this)->assertCreated();

    $ticket = SupportTicket::firstOrFail();
    $envois = Http::recorded()->map(fn ($paire) => $paire[0]['text'])->values();

    // Ni le contexte joint ni le passage automatique au tri : un message par demande.
    expect($envois)->toHaveCount(1)
        ->and($envois[0])->toContain($ticket->reference)->toContain('Nouvelle demande')->toContain($this->ecole->name);
});

it('annonce un changement de statut fait par l’équipe', function () {
    config(['care.slack.webhook' => null]);
    deposerDemande($this)->assertCreated();
    config(['care.slack.webhook' => WEBHOOK_TEST]);
    $ticket = SupportTicket::firstOrFail();

    $agent = \App\Models\User::create([
        'name' => 'Aïcha Support', 'email' => 'aicha@klassci.test', 'password' => 'x', 'role' => 'support', 'is_active' => true,
    ]);

    DB::transaction(fn () => app(Journal::class)->consigner(
        $ticket, TypeEvenement::StatutChange, Acteur::personnel($agent), 'TRIAGE_PENDING', 'IN_PROGRESS',
    ));
    $this->app->terminate();

    Http::assertSent(fn (Request $r) => str_contains($r['text'], 'Statut :') && str_contains($r['text'], '(par Aïcha Support)'));
});

it('ne fait sortir ni la description ni le titre qui en est tiré', function () {
    deposerDemande($this)->assertCreated();

    $ticket = SupportTicket::firstOrFail();
    $sorti = Http::recorded()->map(fn ($paire) => json_encode($paire[0]->data(), JSON_UNESCAPED_UNICODE))->implode("\n");

    expect(Http::recorded())->not->toBeEmpty()
        ->and($sorti)->not->toContain($ticket->description)
        ->and($sorti)->not->toContain((string) $ticket->title);
});

it('n’envoie rien tant que le webhook n’est pas posé', function () {
    config(['care.slack.webhook' => null]);

    deposerDemande($this)->assertCreated();

    Http::assertNothingSent();
});

it('n’annonce jamais une demande restreinte pour raison de sécurité', function () {
    config(['care.slack.webhook' => null]);
    deposerDemande($this)->assertCreated();
    config(['care.slack.webhook' => WEBHOOK_TEST]);
    $ticket = SupportTicket::firstOrFail();
    $ticket->forceFill(['is_security_restricted' => true])->save();

    app(AnnonceSlack::class)->envoyer($ticket->events()->latest('id')->firstOrFail());

    Http::assertNothingSent();
});

it('n’annonce rien d’une transaction annulée', function () {
    // Demande créée sans webhook : aucune annonce ne reste en attente.
    config(['care.slack.webhook' => null]);
    deposerDemande($this)->assertCreated();
    config(['care.slack.webhook' => WEBHOOK_TEST]);
    $ticket = SupportTicket::firstOrFail();

    try {
        DB::transaction(function () use ($ticket) {
            app(Journal::class)->consigner($ticket, TypeEvenement::ReponseClient, Acteur::client(1, 'Parent'));
            throw new RuntimeException('annulation');
        });
    } catch (RuntimeException) {
    }
    $this->app->terminate();

    Http::assertNothingSent();
});

it('annonce une réponse de l’école une fois la transaction validée', function () {
    config(['care.slack.webhook' => null]);
    deposerDemande($this)->assertCreated();
    config(['care.slack.webhook' => WEBHOOK_TEST]);
    $ticket = SupportTicket::firstOrFail();

    DB::transaction(fn () => app(Journal::class)->consigner($ticket, TypeEvenement::ReponseClient, Acteur::client(1, 'Parent')));
    $this->app->terminate();

    Http::assertSent(fn (Request $r) => $r->url() === WEBHOOK_TEST && str_contains($r['text'], "L'école a répondu"));
});

it('laisse la demande aboutir quand Slack est en panne', function () {
    Http::fake([WEBHOOK_TEST => fn () => throw new ConnectionException('injoignable')]);
    \Illuminate\Support\Facades\Log::spy();

    deposerDemande($this)->assertCreated();

    expect(SupportTicket::count())->toBe(1);
    // Seule trace d'un échec : elle doit exister.
    \Illuminate\Support\Facades\Log::shouldHaveReceived('warning')
        ->withArgs(fn ($message) => str_contains($message, 'annonce Slack impossible'));
});

it('neutralise la mise en forme Slack dans le nom d’une école', function () {
    deposerDemande($this)->assertCreated();
    $ticket = SupportTicket::firstOrFail();
    $this->ecole->forceFill(['name' => 'Voir <https://piege.test|ici> & payer'])->save();

    $message = app(AnnonceSlack::class)->message($ticket->fresh('tenant'), $ticket->events()->firstOrFail());

    expect($message['blocks'][0]['text']['text'])
        ->toContain('&lt;https://piege.test|ici&gt; &amp; payer')
        ->not->toContain('<https://piege.test|ici>')
        ->and($message['text'])->not->toContain('<https://piege.test|ici>');
});
