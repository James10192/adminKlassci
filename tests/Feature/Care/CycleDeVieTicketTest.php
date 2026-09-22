<?php

use App\Domain\Care\Tickets\Actions\ClasserTicket;
use App\Domain\Care\Tickets\Actions\RepondreTicket;
use App\Domain\Care\Tickets\Enums\Severite;
use App\Domain\Care\Tickets\Enums\StatutTicket as S;
use App\Domain\Care\Tickets\Enums\VisibiliteMessage;
use App\Domain\Care\Tickets\Exceptions\TransitionRefusee;
use App\Domain\Care\Tickets\Models\SupportTicket;
use App\Domain\Care\Tickets\Services\Acteur;
use App\Domain\Care\Tickets\Services\TicketStateMachine;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Tests\Feature\Care\Support;

beforeEach(function () {
    $ecole = Support::instance('presentation');
    $this->withToken(Support::jeton($ecole))->withHeader('Idempotency-Key', 'cle-cycle-0001')
        ->postJson('/api/v1/support/tickets', Support::soumission());
    $this->ticket = SupportTicket::firstOrFail();
    $this->etats = app(TicketStateMachine::class);
    $this->staff = User::create(['name' => 'Aïcha', 'email' => 'a@klassci.com', 'password' => 'x', 'role' => 'support', 'is_active' => true]);
});

it('franchit une transition permise, date le tri et journalise', function () {
    $this->etats->franchir($this->ticket, S::Triaged, Acteur::personnel($this->staff));

    expect($this->ticket->fresh()->status)->toBe(S::Triaged)
        ->and($this->ticket->fresh()->triaged_at)->not->toBeNull()
        ->and($this->ticket->events()->reorder()->latest('id')->first()->to_value)->toBe('TRIAGED');
});

it('refuse une transition non permise', function () {
    $this->etats->franchir($this->ticket, S::Deployed, Acteur::personnel($this->staff));
})->throws(TransitionRefusee::class);

it('exige un motif pour rejeter', function () {
    $this->etats->franchir($this->ticket, S::Rejected, Acteur::personnel($this->staff), 'non');
})->throws(TransitionRefusee::class);

it('efface les dates de fin a la reouverture', function () {
    $staff = Acteur::personnel($this->staff);
    $this->etats->franchir($this->ticket, S::Resolved, $staff);
    $this->etats->franchir($this->ticket, S::Closed, $staff);
    $this->etats->franchir($this->ticket, S::Triaged, $staff);

    $t = $this->ticket->fresh();
    expect($t->closed_at)->toBeNull()->and($t->resolved_at)->toBeNull();
});

it('rend le journal immuable', function () {
    $this->ticket->events()->first()->update(['reason' => 'réécrit']);
})->throws(LogicException::class);

it('date la premiere reponse publique, pas une note interne', function () {
    $repondre = app(RepondreTicket::class);
    $repondre->executer($this->ticket, $this->staff, 'Note interne.', VisibiliteMessage::InterneSupport);
    expect($this->ticket->fresh()->first_response_at)->toBeNull();

    $repondre->executer($this->ticket, $this->staff, 'Bonjour, nous regardons.', VisibiliteMessage::PublicClient);
    expect($this->ticket->fresh()->first_response_at)->not->toBeNull();
});

it('exige un motif pour changer une severite deja posee, pas pour la poser', function () {
    $classer = app(ClasserTicket::class);
    $classer->executer($this->ticket, $this->staff, null, Severite::Sev3, null, null);

    expect(fn () => $classer->executer($this->ticket->fresh(), $this->staff, null, Severite::Sev1, null, null))
        ->toThrow(TransitionRefusee::class);

    $classer->executer($this->ticket->fresh(), $this->staff, null, Severite::Sev1, null, null, 'Trois écoles touchées ce matin.');
    expect($this->ticket->fresh()->severity)->toBe(Severite::Sev1);
});

it('accorde les capacites par role depuis la configuration', function () {
    $billing = User::create(['name' => 'B', 'email' => 'b@klassci.com', 'password' => 'x', 'role' => 'billing', 'is_active' => true]);
    $inactif = User::create(['name' => 'I', 'email' => 'i@klassci.com', 'password' => 'x', 'role' => 'super_admin', 'is_active' => false]);

    expect(Gate::forUser($this->staff)->allows('support.tickets.manage'))->toBeTrue()
        ->and(Gate::forUser($this->staff)->allows('support.security.view'))->toBeFalse()
        ->and(Gate::forUser($billing)->allows('support.tickets.view'))->toBeTrue()
        ->and(Gate::forUser($billing)->allows('support.tickets.manage'))->toBeFalse()
        ->and(Gate::forUser($inactif)->allows('support.tickets.view'))->toBeFalse();
});
