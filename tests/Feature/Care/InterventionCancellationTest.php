<?php

use App\Domain\Care\Tickets\Actions\AnnulerIntervention;
use App\Domain\Care\Tickets\Actions\PreparerIntervention;
use App\Domain\Care\Tickets\Models\SupportTicket;
use App\Domain\Care\Tickets\Models\SupportTicketIntervention;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\Feature\Care\Support;

beforeEach(function () {
    $ecole = Support::instance('presentation');
    $this->withToken(Support::jeton($ecole))
        ->withHeader('Idempotency-Key', 'cle-intervention-annulation')
        ->postJson('/api/v1/support/tickets', Support::soumission())->assertCreated();

    $this->ticket = SupportTicket::firstOrFail();
    $this->support = User::create([
        'name' => 'Aicha', 'email' => 'annulation@klassci.com',
        'password' => 'x', 'role' => 'support', 'is_active' => true,
    ]);
    $this->intervention = app(PreparerIntervention::class)->executer(
        $this->ticket, $this->support, 'cancel-key-001',
        'Valider inscription', 'Verifier cette inscription par la scolarite.',
        'inscriptions.validate'
    );
});

it('annule une intervention sans délivrer de fausse notification et conserve le ticket', function () {
    $i = app(AnnulerIntervention::class)->executer(
        $this->intervention->id, $this->support, 'Action non necessaire'
    );
    expect($i->status)->toBe(SupportTicketIntervention::CANCELLED)
        ->and(DB::table('support_ticket_intervention_events')->count())->toBe(2)
        ->and($this->ticket->fresh()->status)->toBe($this->ticket->status);
});

it('rejoue sans écrire un second événement', function () {
    $action = app(AnnulerIntervention::class);
    $action->executer($this->intervention->id, $this->support, 'Action non necessaire');
    $action->executer($this->intervention->id, $this->support, 'Action non necessaire');
    expect(DB::table('support_ticket_intervention_events')->count())->toBe(2);
});

it('refuse un operateur desactive ou sans droit', function () {
    $this->support->update(['is_active' => false]);
    expect(fn () => app(AnnulerIntervention::class)->executer(
        $this->intervention->id, $this->support, 'Action non necessaire'
    ))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
    expect($this->intervention->fresh()->status)->toBe(SupportTicketIntervention::PENDING_ROUTING);
});

it('refuse de modifier une intervention déjà exécutée', function () {
    $this->intervention->forceFill(['status' => SupportTicketIntervention::COMPLETED])->save();
    expect(fn () => app(AnnulerIntervention::class)->executer(
        $this->intervention->id, $this->support, 'Action non necessaire'
    ))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
    expect(DB::table('support_ticket_intervention_events')->count())->toBe(1);
});

it('refuse le dossier devenu restreint et un motif insuffisant', function () {
    expect(fn () => app(AnnulerIntervention::class)->executer(
        $this->intervention->id, $this->support, 'court'
    ))->toThrow(ValidationException::class);
    $this->ticket->forceFill(['is_security_restricted' => true])->save();
    expect(fn () => app(AnnulerIntervention::class)->executer(
        $this->intervention->id, $this->support, 'Action non necessaire'
    ))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
    expect(DB::table('support_ticket_intervention_events')->count())->toBe(1);
});
