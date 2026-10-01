<?php

use App\Domain\Care\Tickets\Actions\RepondreTicket;
use App\Domain\Care\Tickets\Enums\VisibiliteMessage;
use App\Domain\Care\Tickets\Models\SupportTicket;
use App\Models\User;
use Illuminate\Support\Carbon;
use Tests\Feature\Care\Support;

/*
 * Ce dont l'école a besoin pour notifier ses utilisateurs : savoir ce qui a
 * bougé depuis sa dernière lecture, et quand le support a répondu.
 */

beforeEach(function () {
    config(['care.slack.webhook' => null]);
    $this->ecole = Support::instance('presentation');
    $this->jeton = Support::jeton($this->ecole);
    $this->reference = $this->withToken($this->jeton)->withHeader('Idempotency-Key', 'cle-suivi-0001')
        ->postJson('/api/v1/support/tickets', Support::soumission())->json('reference');
    $this->ticket = SupportTicket::where('reference', $this->reference)->firstOrFail();
    $this->staff = User::create(['name' => 'Support', 'email' => 's@klassci.com', 'password' => 'x', 'role' => 'support', 'is_active' => true]);
});

afterEach(fn () => Carbon::setTestNow());

it('ne rend que les demandes touchees depuis la date donnee', function () {
    Carbon::setTestNow(now()->addHour());
    $repere = now()->toIso8601String();

    $this->withToken($this->jeton)->getJson('/api/v1/support/tickets?reporter=42&mis_a_jour_depuis='.urlencode($repere))
        ->assertOk()->assertJsonCount(0, 'data');

    Carbon::setTestNow(now()->addMinute());
    app(RepondreTicket::class)->executer($this->ticket, $this->staff, 'Corrigé, merci.', VisibiliteMessage::PublicClient);

    $this->withToken($this->jeton)->getJson('/api/v1/support/tickets?reporter=42&mis_a_jour_depuis='.urlencode($repere))
        ->assertOk()->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.reference', $this->reference)
        ->assertJsonPath('data.0.derniere_reponse_support_le', now()->toIso8601String());
});

it('ne signale pas une note interne a l ecole', function () {
    Carbon::setTestNow(now()->addHour());
    $repere = now()->toIso8601String();
    Carbon::setTestNow(now()->addMinute());

    app(RepondreTicket::class)->executer($this->ticket, $this->staff, 'Note pour l équipe.', VisibiliteMessage::InterneSupport);

    $this->withToken($this->jeton)->getJson('/api/v1/support/tickets?reporter=42&mis_a_jour_depuis='.urlencode($repere))
        ->assertOk()->assertJsonCount(0, 'data');
});

it('rend derniere_reponse_support_le nul tant que le support n a pas repondu', function () {
    $this->withToken($this->jeton)->getJson('/api/v1/support/tickets?reporter=42')
        ->assertOk()->assertJsonPath('data.0.derniere_reponse_support_le', null);
});

it('borne le filtre mis_a_jour_depuis', function () {
    $this->withToken($this->jeton)->getJson('/api/v1/support/tickets?reporter=42&mis_a_jour_depuis=pas-une-date')
        ->assertStatus(422);
    $this->withToken($this->jeton)->getJson('/api/v1/support/tickets?reporter=42&mis_a_jour_depuis='.urlencode(now()->subDays(90)->toIso8601String()))
        ->assertStatus(422);
});

it('liste toute l ecole sans rapporteur en scope school', function () {
    $this->withToken($this->jeton)->getJson('/api/v1/support/tickets?scope=school')
        ->assertOk()->assertJsonPath('data.0.reference', $this->reference);
    $this->withToken($this->jeton)->getJson('/api/v1/support/tickets')->assertStatus(422);
});

it('rend le statut client RESOLU une fois la demande resolue', function () {
    app(\App\Domain\Care\Tickets\Services\TicketStateMachine::class)->franchir(
        $this->ticket, \App\Domain\Care\Tickets\Enums\StatutTicket::Resolved, \App\Domain\Care\Tickets\Services\Acteur::personnel($this->staff));

    $this->withToken($this->jeton)->getJson('/api/v1/support/tickets?scope=school')
        ->assertOk()->assertJsonPath('data.0.statut.code', 'RESOLU');
});
