<?php

use App\Domain\Care\Tickets\Actions\PreparerIntervention;
use App\Domain\Care\Tickets\Models\SupportTicket;
use App\Domain\Care\Tickets\Models\SupportTicketIntervention;
use App\Domain\Care\Tickets\Enums\StatutTicket;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\Feature\Care\Support;

beforeEach(function () {
    $ecole = Support::instance('presentation');
    $this->withToken(Support::jeton($ecole))
        ->withHeader('Idempotency-Key', 'cle-intervention-123')
        ->postJson('/api/v1/support/tickets', Support::soumission())->assertCreated();
    $this->ticket = SupportTicket::firstOrFail();
    $this->support = User::create(['name' => 'Aicha', 'email' => 'a@klassci.com', 'password' => 'x', 'role' => 'support', 'is_active' => true]);
});

function preparerTestIntervention($ticket, $user, $key = 'action-key-0001', $title = 'Valider inscription') {
    return app(PreparerIntervention::class)->executer(
        $ticket, $user, $key, $title,
        'Verifier la validation de cette inscription auprès du service competent.',
        'inscriptions.validate', null,
    );
}

it('prepare une intervention sans annoncer faussement son envoi', function () {
    $i = preparerTestIntervention($this->ticket, $this->support);
    expect($i->tenant_id)->toBe($this->ticket->tenant_id)
        ->and($i->status)->toBe(SupportTicketIntervention::PENDING_ROUTING)
        ->and($i->target_external_user_id)->toBeNull()
        ->and(DB::table('support_ticket_intervention_events')->count())->toBe(1)
        ->and($this->ticket->fresh()->status)->toBe(StatutTicket::TriagePending);
});

it('ne cree pas deux interventions pour une cle rejouee', function () {
    $a = preparerTestIntervention($this->ticket, $this->support);
    $b = preparerTestIntervention($this->ticket, $this->support);
    expect($a->id)->toBe($b->id)
        ->and(SupportTicketIntervention::count())->toBe(1)
        ->and(DB::table('support_ticket_intervention_events')->count())->toBe(1);
});

it('refuse de reutiliser la meme cle pour une autre action', function () {
    preparerTestIntervention($this->ticket, $this->support);
    expect(fn () => preparerTestIntervention($this->ticket, $this->support, 'action-key-0001', 'Modifier les notes'))
        ->toThrow(ValidationException::class);
    expect(SupportTicketIntervention::count())->toBe(1);
});

it('refuse une intervention sur un dossier restreint ou ferme', function () {
    $this->ticket->forceFill(['is_security_restricted' => true])->save();
    expect(fn () => preparerTestIntervention($this->ticket, $this->support))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
    $this->ticket->forceFill(['is_security_restricted' => false, 'status' => StatutTicket::Closed])->save();
    expect(fn () => preparerTestIntervention($this->ticket, $this->support))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
    expect(SupportTicketIntervention::count())->toBe(0);
});

it('refuse une permission absente ou malformee', function () {
    expect(fn () => app(PreparerIntervention::class)->executer(
        $this->ticket, $this->support, 'action-key-0002', 'Valider inscription',
        'Veuillez verifier ce dossier et son inscription.', '.*'
    ))->toThrow(ValidationException::class);
    expect(SupportTicketIntervention::count())->toBe(0);
});


it('refuse un operateur sans permission de gestion meme avec le ticket connu', function () {
    config(['care.capacites_par_role.billing' => []]);
    $autre = User::create(['name' => 'Comptable', 'email' => 'comptable@klassci.com', 'password' => 'x', 'role' => 'billing', 'is_active' => true]);

    expect(fn () => preparerTestIntervention($this->ticket, $autre))
        ->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);

    expect(SupportTicketIntervention::count())->toBe(0);
});

it('refuse une cible externe incorrecte sans créer de demande', function () {
    expect(fn () => app(PreparerIntervention::class)->executer(
        $this->ticket, $this->support, 'action-key-0003', 'Valider inscription',
        'Veuillez verifier ce dossier et son inscription.', 'inscriptions.validate', 0,
    ))->toThrow(ValidationException::class);

    expect(SupportTicketIntervention::count())->toBe(0);
});
