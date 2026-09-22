<?php

use App\Domain\Care\Tickets\Enums\StatutClient;
use App\Domain\Care\Tickets\Enums\StatutTicket;
use App\Domain\Care\Tickets\Services\TicketStateMachine;

it('projette chaque statut interne vers un statut client', function (StatutTicket $statut) {
    expect($statut->statutClient())->toBeInstanceOf(StatutClient::class)
        ->and($statut->libelle())->not->toBe('');
})->with(StatutTicket::cases());

it('ne montre jamais un statut termine comme ouvert', function () {
    foreach ([StatutTicket::Resolved, StatutTicket::Closed, StatutTicket::Rejected, StatutTicket::Duplicate] as $s) {
        expect($s->estOuvert())->toBeFalse();
    }
    expect(StatutTicket::valeursOuvertes())->toContain('TRIAGE_PENDING')->not->toContain('CLOSED');
});

it('declare des transitions pour chaque statut', function (StatutTicket $statut) {
    expect(TicketStateMachine::transitions())->toHaveKey($statut->value);
})->with(StatutTicket::cases());

it('ne declare que des statuts existants comme cibles', function () {
    foreach (TicketStateMachine::transitions() as $cibles) {
        foreach ($cibles as $cible) {
            expect($cible)->toBeInstanceOf(StatutTicket::class);
        }
    }
});
